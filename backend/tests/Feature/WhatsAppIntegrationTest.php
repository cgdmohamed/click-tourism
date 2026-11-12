<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadStage;
use App\Modules\Integrations\Contracts\WhatsAppSender;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Jobs\SendWhatsAppMessageJob;
use App\Modules\Integrations\Models\MessagingConsent;
use App\Modules\Integrations\Models\OutboxEvent;
use App\Modules\Integrations\Models\WebhookEvent;
use App\Modules\Integrations\Models\WhatsAppMessage;
use App\Modules\Integrations\Services\ConsentService;
use App\Modules\Integrations\Services\WhatsAppOutboundService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** WhatsApp Cloud API: inbound log, status updates, consent-gated outbound with retry (Http::fake). */
class WhatsAppIntegrationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const SECRET = 'wa-app-secret';

    private const TOKEN = 'EAAG-whatsapp-access-token';

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
        config()->set('services.whatsapp.app_secret', self::SECRET);
        config()->set('services.whatsapp.access_token', self::TOKEN);
        $settings = app(SettingService::class);
        $settings->set('integrations', 'whatsapp_enabled', 'true');
        $settings->set('integrations', 'whatsapp_outbound_enabled', 'true');
        $settings->set('integrations', 'whatsapp_phone_number_id', '10987654321');

        $this->lead = Lead::query()->create([
            'name' => 'Salma Hassan',
            'phone' => '+20 122 333 4455',
            'branch_id' => $this->branch->id,
            'status' => 'open',
            'consent' => true,
            'stage_id' => LeadStage::query()->where('code', 'new')->value('id'),
        ]);

        foreach (['leads.view', 'leads.update', 'settings.view', 'settings.update'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['leads.view', 'leads.update', 'settings.view', 'settings.update']);
        Sanctum::actingAs($this->user);
    }

    private function optIn(string $phone = '201223334455'): void
    {
        app(ConsentService::class)->record($phone, 'opt_in', 'written', $this->lead->id);
    }

    private function inbound(string $id = 'wamid.IN-1', string $text = 'Hello', string $from = '201223334455'): void
    {
        $this->postWebhook(json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Salma Hassan']]],
                'messages' => [['id' => $id, 'from' => $from, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]],
        ], JSON_THROW_ON_ERROR))->assertOk();
    }

    private function postWebhook(string $payload, string $secret = self::SECRET)
    {
        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        ], $payload);
    }

    private function statusWebhook(string $waId, string $status, array $extra = [])
    {
        return $this->postWebhook(json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => [
                'statuses' => [array_merge(['id' => $waId, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '201223334455'], $extra)],
            ]]]]],
        ], JSON_THROW_ON_ERROR));
    }

    private function fakeSend(?string $id = null): void
    {
        // Provider message ids are unique; generate one per call unless a fixed id is needed.
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => $id ?? 'wamid.OUT-'.uniqid()]]])]);
    }

    private function sendJson(array $body, ?string $key = null)
    {
        return $this->postJson('/api/v1/integrations/whatsapp/messages', $body, $key ? ['Idempotency-Key' => $key] : []);
    }

    // ----- disabled / signature -------------------------------------------------

    public function test_disabled_whatsapp_ignores_webhooks_and_refuses_sending(): void
    {
        app(SettingService::class)->set('integrations', 'whatsapp_enabled', 'false');
        Http::fake();

        $this->postWebhook('{"entry":[]}')->assertStatus(404)->assertJsonPath('code', 'integration_disabled');
        $this->optIn();
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertStatus(422)->assertJsonPath('code', 'integration_disabled');

        $this->assertSame(0, WebhookEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_outbound_is_a_separate_switch_from_inbound_logging(): void
    {
        app(SettingService::class)->set('integrations', 'whatsapp_outbound_enabled', 'false');
        $this->optIn();
        Http::fake();

        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertStatus(422)->assertJsonPath('code', 'integration_disabled');
        $this->inbound(); // inbound still works
        $this->assertSame(1, WhatsAppMessage::query()->where('direction', 'in')->count());
    }

    public function test_a_bad_signature_is_rejected_and_nothing_is_logged(): void
    {
        $this->postWebhook('{"entry":[]}', 'wrong')->assertStatus(403)->assertJsonPath('code', 'invalid_signature');

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    // ----- inbound --------------------------------------------------------------

    public function test_an_inbound_message_is_logged_and_linked_to_the_lead_by_phone_even_with_different_formatting(): void
    {
        $this->inbound();

        $message = WhatsAppMessage::query()->firstOrFail();
        $this->assertSame('in', $message->direction);
        $this->assertSame($this->lead->id, $message->lead_id);
        $this->assertSame('Hello', $message->body);
        $this->assertSame(1, Lead::query()->count(), 'No second lead for a known phone.');
        $this->assertSame(1, $this->lead->activities()->where('type', 'whatsapp')->count());
    }

    public function test_an_inbound_message_from_a_known_customer_links_to_the_customer_without_creating_a_lead(): void
    {
        $customer = $this->customer();
        $customer->update(['phone' => '0100 555 6677']);

        $this->inbound('wamid.IN-C', 'Booking question', '201005556677');

        $message = WhatsAppMessage::query()->where('wa_message_id', 'wamid.IN-C')->firstOrFail();
        $this->assertSame($customer->id, $message->customer_id);
        $this->assertNull($message->lead_id);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_an_unknown_sender_becomes_a_whatsapp_lead_and_a_redelivered_message_id_is_not_duplicated(): void
    {
        $this->inbound('wamid.IN-N', 'New enquiry', '201999888777');
        $this->inbound('wamid.IN-N', 'New enquiry', '201999888777');

        $this->assertSame(2, Lead::query()->count());
        $this->assertSame('whatsapp', Lead::query()->latest('id')->first()->source?->code);
        $this->assertSame(1, WhatsAppMessage::query()->where('wa_message_id', 'wamid.IN-N')->count());
    }

    public function test_replayed_webhooks_are_deduplicated(): void
    {
        $this->inbound('wamid.IN-R');
        $payload = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['id' => 'wamid.IN-R2', 'from' => '201223334455', 'type' => 'text', 'text' => ['body' => 'x']]]]]]]]]);

        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'received');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(2, WhatsAppMessage::query()->count());
    }

    public function test_webhook_event_storage_redacts_phones_and_message_text(): void
    {
        $this->inbound('wamid.IN-P', 'my passport number is X123');

        $stored = json_encode(WebhookEvent::query()->latest('id')->firstOrFail()->payload);
        $this->assertStringNotContainsString('X123', $stored);
        $this->assertStringNotContainsString('201223334455', $stored);
    }

    public function test_a_stop_keyword_records_an_opt_out_that_blocks_later_sends(): void
    {
        $this->optIn();
        $this->inbound('wamid.IN-S', 'STOP');
        Http::fake();

        $this->assertSame('opt_out', app(ConsentService::class)->status('201223334455'));
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertStatus(422)->assertJsonPath('code', 'consent_withdrawn');
        Http::assertNothingSent();
    }

    // ----- consent / window -----------------------------------------------------

    public function test_sending_without_a_recorded_opt_in_is_refused_and_nothing_is_queued_or_sent(): void
    {
        Http::fake();

        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertStatus(422)->assertJsonPath('code', 'consent_missing');

        $this->assertSame(0, WhatsAppMessage::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_lead_consent_flag_alone_is_not_a_whatsapp_opt_in(): void
    {
        $this->assertTrue($this->lead->consent);
        Http::fake();

        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertJsonPath('code', 'consent_missing');
    }

    public function test_free_text_requires_the_24h_service_window_but_templates_do_not(): void
    {
        $this->optIn();
        $this->fakeSend();

        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'text', 'body' => 'Hi'])
            ->assertStatus(422)->assertJsonPath('code', 'outside_service_window');

        $this->inbound('wamid.IN-W', 'Hello');
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'text', 'body' => 'Hi back'])->assertStatus(202);

        WhatsAppMessage::query()->where('direction', 'in')->update(['created_at' => now()->subHours(25)]);
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'text', 'body' => 'Late'])
            ->assertJsonPath('code', 'outside_service_window');
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'follow_up', 'language' => 'ar'])->assertStatus(202);
    }

    public function test_recording_consent_through_the_api_enables_sending(): void
    {
        $this->postJson('/api/v1/integrations/whatsapp/consents', [
            'lead_id' => $this->lead->public_id,
            'status' => 'opt_in',
            'source' => 'lead_form',
        ])->assertCreated()->assertJsonPath('data.status', 'opt_in');

        $this->assertSame('opt_in', app(ConsentService::class)->status('201223334455'));
        $this->assertSame(1, MessagingConsent::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'integrations.whatsapp_consent_opt_in')->count());
    }

    // ----- outbound / outbox / retry -------------------------------------------

    public function test_a_template_message_goes_through_the_outbox_is_sent_and_is_idempotent_per_key(): void
    {
        $this->optIn();
        $this->fakeSend('wamid.OUT-T');

        $body = ['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'trip_offer', 'language' => 'en_US',
            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Luxor']]]]];

        $first = $this->sendJson($body, 'key-1')->assertStatus(202)->assertJsonPath('data.status', 'sent');
        $second = $this->sendJson($body, 'key-1')->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();
        $this->assertSame('wamid.OUT-T', $message->wa_message_id);
        $this->assertSame(1, $message->attempts);
        $this->assertSame($this->user->id, $message->created_by);
        $this->assertNotNull(OutboxEvent::query()->firstOrFail()->published_at);
        $this->assertSame(1, OutboxEvent::query()->count());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return str_ends_with($request->url(), '/10987654321/messages')
                && ! str_contains($request->url(), self::TOKEN)
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '201223334455'
                && $data['type'] === 'template'
                && $data['template']['name'] === 'trip_offer'
                && $data['template']['language']['code'] === 'en_US';
        });
        $this->assertSame(2, $this->lead->activities()->where('type', 'whatsapp')->count());
    }

    public function test_the_outbox_row_is_written_in_the_same_transaction_and_published_by_the_scheduler_command(): void
    {
        $this->optIn();
        Queue::fake();

        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);

        // Simulate a lost broker publish: the row is released and the command republishes it.
        OutboxEvent::query()->update(['published_at' => null]);
        $this->artisan('integrations:dispatch-outbox')->assertSuccessful();
        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
        $this->assertNotNull(OutboxEvent::query()->firstOrFail()->published_at);
    }

    public function test_transient_failures_are_retried_with_backoff_and_a_message_is_never_sent_twice(): void
    {
        $this->optIn();
        Queue::fake();
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])->assertStatus(202);
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['code' => 130429, 'message' => 'rate']], 429)
                ->push('upstream down', 503)
                ->push(['messages' => [['id' => 'wamid.OUT-R']]]),
        ]);

        $job = new SendWhatsAppMessageJob($message->id);
        $this->assertSame([30, 120, 600, 1800], $job->backoff());
        $this->assertSame(5, $job->tries);

        foreach ([1, 2] as $attempt) {
            try {
                $job->handle(app(WhatsAppOutboundService::class), app(WhatsAppSender::class));
                $this->fail('Transient failure should be re-thrown for the queue to retry.');
            } catch (IntegrationException $exception) {
                $this->assertTrue($exception->transient);
            }
            $message->refresh();
            $this->assertSame('queued', $message->status);
            $this->assertSame($attempt, $message->attempts);
            $this->assertNull($message->wa_message_id);
        }

        $job->handle(app(WhatsAppOutboundService::class), app(WhatsAppSender::class));
        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame(3, $message->attempts);
        $this->assertSame('wamid.OUT-R', $message->wa_message_id);
        $this->assertNull($message->error_message);

        // A duplicate job (at-least-once delivery) is a no-op.
        $job->handle(app(WhatsAppOutboundService::class), app(WhatsAppSender::class));
        Http::assertSentCount(3);
    }

    public function test_a_permanent_provider_error_fails_the_message_without_retry(): void
    {
        $this->optIn();
        Queue::fake();
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'not_approved'])->assertStatus(202);
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 132001, 'message' => 'template missing']], 404)]);

        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppOutboundService::class), app(WhatsAppSender::class));

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('132001', $message->error_code);
        $this->assertNotNull($message->failed_at);
        Http::assertSentCount(1);
    }

    public function test_exhausted_retries_mark_the_message_failed(): void
    {
        $this->optIn();
        Queue::fake();
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])->assertStatus(202);
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();

        (new SendWhatsAppMessageJob($message->id))->failed(new \RuntimeException('gave up'));

        $this->assertSame('failed', $message->refresh()->status);
        $this->assertSame('retries_exhausted', $message->error_code);
    }

    public function test_consent_withdrawn_between_queueing_and_sending_blocks_the_send(): void
    {
        $this->optIn();
        Queue::fake();
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])->assertStatus(202);
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();
        app(ConsentService::class)->record('201223334455', 'opt_out', 'manual');
        Http::fake();

        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppOutboundService::class), app(WhatsAppSender::class));

        $this->assertSame('failed', $message->refresh()->status);
        $this->assertSame('consent_withdrawn', $message->error_code);
        Http::assertNothingSent();
    }

    // ----- status webhooks ------------------------------------------------------

    public function test_status_webhooks_advance_the_message_and_never_move_it_backwards(): void
    {
        $this->optIn();
        $this->fakeSend('wamid.OUT-S');
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])->assertStatus(202);

        $this->statusWebhook('wamid.OUT-S', 'delivered')->assertOk();
        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);

        $this->statusWebhook('wamid.OUT-S', 'sent')->assertOk(); // late, out of order
        $this->assertSame('delivered', $message->refresh()->status);

        $this->statusWebhook('wamid.OUT-S', 'read')->assertOk();
        $this->assertSame('read', $message->refresh()->status);
        $this->assertNotNull($message->read_at);
    }

    public function test_a_failed_status_records_the_provider_error(): void
    {
        $this->optIn();
        $this->fakeSend('wamid.OUT-F');
        $this->sendJson(['lead_id' => $this->lead->public_id, 'type' => 'template', 'template_name' => 'welcome'])->assertStatus(202);

        $this->statusWebhook('wamid.OUT-F', 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]])->assertOk();

        $message = WhatsAppMessage::query()->where('direction', 'out')->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertSame('131026', $message->error_code);
        $this->assertSame('Message undeliverable', $message->error_message);
    }

    public function test_a_status_for_an_unknown_message_is_ignored(): void
    {
        $this->statusWebhook('wamid.UNKNOWN', 'read')->assertOk();

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    // ----- message log ----------------------------------------------------------

    public function test_the_message_log_lists_messages_for_a_lead_and_is_permission_protected(): void
    {
        $this->inbound();

        $this->getJson('/api/v1/integrations/whatsapp/messages?lead_id='.$this->lead->public_id)
            ->assertOk()->assertJsonPath('data.0.direction', 'in')->assertJsonPath('data.0.body', 'Hello');

        $nobody = User::factory()->create();
        Sanctum::actingAs($nobody);
        $this->getJson('/api/v1/integrations/whatsapp/messages')->assertForbidden();
        $this->postJson('/api/v1/integrations/whatsapp/messages', [])->assertForbidden();
    }
}
