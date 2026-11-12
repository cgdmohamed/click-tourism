<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Contracts\MetaLeadFetcher;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Jobs\ProcessMetaLeadJob;
use App\Modules\Integrations\Models\WebhookEvent;
use App\Modules\Integrations\Services\IntegrationSettings;
use App\Modules\Integrations\Services\MetaLeadIngestionService;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Meta Lead Ads: signed webhook -> Graph API fetch (Http::fake) -> idempotent CRM lead. */
class MetaLeadAdsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const SECRET = 'meta-app-secret';

    private const TOKEN = 'EAAB-meta-access-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
        config()->set('services.meta.app_secret', self::SECRET);
        config()->set('services.meta.access_token', self::TOKEN);
        app(SettingService::class)->set('integrations', 'meta_leads_enabled', 'true');
    }

    private function payload(string $leadgenId = '444555666', string $formId = '9001'): string
    {
        return json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => '1234567890',
                'time' => 1700000000,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => $leadgenId,
                        'page_id' => '1234567890',
                        'form_id' => $formId,
                        'ad_id' => '777',
                        'created_time' => 1700000000,
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function postSigned(string $payload, ?string $secret = self::SECRET)
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $payload, $secret);
        }

        return $this->call('POST', '/api/v1/webhooks/meta', [], [], [], $headers, $payload);
    }

    private function fakeGraph(string $name = 'Nora Adel', string $phone = '+20 111 222 3344', string $email = 'nora@example.com'): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '444555666',
                'created_time' => '2026-11-12T08:00:00+0000',
                'ad_id' => '777',
                'form_id' => '9001',
                'field_data' => [
                    ['name' => 'full_name', 'values' => [$name]],
                    ['name' => 'phone_number', 'values' => [$phone]],
                    ['name' => 'email', 'values' => [$email]],
                    ['name' => 'trip_type', 'values' => ['Umrah']],
                ],
            ]),
        ]);
    }

    public function test_the_integration_is_disabled_by_default(): void
    {
        app(SettingService::class)->set('integrations', 'meta_leads_enabled', 'false');
        config()->set('services.meta.verify_token', 'verify-me');
        Http::fake();

        $this->postSigned($this->payload())->assertStatus(404)->assertJsonPath('code', 'integration_disabled');
        $this->getJson('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=c')
            ->assertStatus(404);

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_fresh_installation_has_the_integration_off(): void
    {
        app(SettingService::class)->set('integrations', 'meta_leads_enabled', 'false');
        $this->assertFalse(app(IntegrationSettings::class)->metaEnabled());
        $this->assertSame('false', SettingService::DEFAULTS['integrations']['meta_leads_enabled']);
        $this->assertSame('false', SettingService::DEFAULTS['integrations']['whatsapp_enabled']);
        $this->assertSame('false', SettingService::DEFAULTS['integrations']['whatsapp_outbound_enabled']);
    }

    public function test_an_invalid_or_missing_signature_is_rejected_before_anything_is_stored_or_fetched(): void
    {
        Http::fake();

        $this->postSigned($this->payload(), 'wrong-secret')->assertStatus(403)->assertJsonPath('code', 'invalid_signature');
        $this->postSigned($this->payload(), null)->assertStatus(403)->assertJsonPath('code', 'invalid_signature');

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_verification_handshake_records_the_verified_time(): void
    {
        config()->set('services.meta.verify_token', 'verify-me');

        $this->get('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=abc123')
            ->assertOk()->assertSee('abc123');

        $this->assertNotSame('', app(SettingService::class)->get('integrations', 'meta_webhook_verified_at'));
        $this->getJson('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=abc123')->assertStatus(403);
    }

    public function test_a_signed_leadgen_event_fetches_details_and_creates_one_lead_with_a_source_and_owner(): void
    {
        $this->fakeGraph();
        AssignmentRule::query()->create([
            'priority' => 10,
            'branch_id' => $this->branch->id,
            'strategy' => 'manual',
            'target_user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $this->postSigned($this->payload())->assertOk()->assertJsonPath('data.status', 'received');

        $lead = Lead::query()->firstOrFail();
        $this->assertSame('Nora Adel', $lead->name);
        $this->assertSame('+20 111 222 3344', $lead->phone);
        $this->assertSame('nora@example.com', $lead->email);
        $this->assertSame('facebook', $lead->source?->code);
        $this->assertSame('444555666', $lead->external_id);
        $this->assertSame($this->branch->id, $lead->branch_id);
        $this->assertSame($this->user->id, $lead->owner_user_id);
        $this->assertStringContainsString('trip_type: Umrah', (string) $lead->notes);

        $event = WebhookEvent::query()->firstOrFail();
        $this->assertSame('processed', $event->status);
        $this->assertSame('created', $event->payload['outcome']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/444555666')
                && ! str_contains($request->url(), self::TOKEN)
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    public function test_replaying_the_same_event_is_a_no_op_and_does_not_call_the_graph_api_again(): void
    {
        $this->fakeGraph();
        $payload = $this->payload();

        $this->postSigned($payload)->assertOk()->assertJsonPath('data.status', 'received');
        $this->postSigned($payload)->assertOk()->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(1, WebhookEvent::query()->count());
        Http::assertSentCount(1);
    }

    public function test_a_new_event_for_an_existing_phone_or_email_does_not_create_a_duplicate_lead(): void
    {
        $this->fakeGraph();
        $this->postSigned($this->payload('444555666'))->assertOk();

        // Same contact, different formatting and a new leadgen id.
        $this->fakeGraph('Nora A.', '0111 222 3344', 'other@example.com');
        $this->postSigned($this->payload('444555999'))->assertOk();

        // Same email, different phone.
        $this->fakeGraph('Nora', '+20 100 999 8877', 'NORA@example.com');
        $this->postSigned($this->payload('444556000'))->assertOk();

        $this->assertSame(1, Lead::query()->count());
        $lead = Lead::query()->firstOrFail();
        $this->assertSame(2, $lead->activities()->where('type', 'note')->count());
        $this->assertSame(3, WebhookEvent::query()->where('status', 'processed')->count());
    }

    public function test_a_known_external_id_is_not_created_twice_even_if_the_event_row_is_new(): void
    {
        $this->fakeGraph();
        $this->postSigned($this->payload())->assertOk();
        WebhookEvent::query()->delete(); // simulate a purged event log

        $this->postSigned($this->payload())->assertOk();

        $this->assertSame(1, Lead::query()->count());
        Http::assertSentCount(1);
    }

    public function test_leads_are_routed_to_the_branch_mapped_to_the_form(): void
    {
        $other = Branch::query()->create(['code' => 'ALX', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'accounting_mode' => 'dimension', 'is_active' => true]);
        $agent = User::factory()->create(['is_available_for_leads' => true]);
        $agent->branches()->attach($other->id);
        AssignmentRule::query()->create(['priority' => 5, 'branch_id' => $other->id, 'strategy' => 'manual', 'target_user_id' => $agent->id, 'is_active' => true]);
        app(SettingService::class)->set('integrations', 'meta_form_branch_map', json_encode(['9001' => $other->public_id]));

        $this->fakeGraph();
        $this->postSigned($this->payload('444555666', '9001'))->assertOk();

        $lead = Lead::query()->firstOrFail();
        $this->assertSame($other->id, $lead->branch_id);
        $this->assertSame($agent->id, $lead->owner_user_id);
    }

    public function test_transient_graph_failures_are_retried_and_then_succeed(): void
    {
        Queue::fake();
        $this->postSigned($this->payload())->assertOk();
        Queue::assertPushed(ProcessMetaLeadJob::class, 1);
        $eventId = WebhookEvent::query()->value('id');

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['code' => 4, 'message' => 'rate limited']], 429)
                ->push(['id' => '444555666', 'form_id' => '9001', 'field_data' => [['name' => 'full_name', 'values' => ['Retry Lead']]]]),
        ]);

        $job = new ProcessMetaLeadJob($eventId);
        try {
            $job->handle(app(MetaLeadIngestionService::class), app(MetaLeadFetcher::class));
            $this->fail('A transient failure must be re-thrown so the queue retries it.');
        } catch (IntegrationException $exception) {
            $this->assertTrue($exception->transient);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
        $this->assertSame('retrying', WebhookEvent::query()->find($eventId)->status);
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame([30, 120, 600, 1800], $job->backoff());

        $job->handle(app(MetaLeadIngestionService::class), app(MetaLeadFetcher::class));

        $this->assertSame('processed', WebhookEvent::query()->find($eventId)->status);
        $this->assertSame(2, WebhookEvent::query()->find($eventId)->attempts);
        $this->assertSame('Retry Lead', Lead::query()->firstOrFail()->name);
    }

    public function test_permanent_graph_errors_fail_the_event_without_retrying_and_a_provider_redelivery_requeues_it(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'bad token']], 400)]);

        $this->postSigned($this->payload())->assertOk();
        $eventId = WebhookEvent::query()->value('id');

        (new ProcessMetaLeadJob($eventId))->handle(app(MetaLeadIngestionService::class), app(MetaLeadFetcher::class));

        $event = WebhookEvent::query()->find($eventId);
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('code 190', (string) $event->last_error);
        $this->assertSame(0, Lead::query()->count());

        // Meta re-delivers once the token has been fixed: the failed event is queued again.
        $this->postSigned($this->payload())->assertOk()->assertJsonPath('data.status', 'received');
        Queue::assertPushed(ProcessMetaLeadJob::class, 2);
    }

    public function test_exhausted_retries_mark_the_event_failed(): void
    {
        Queue::fake();
        $this->postSigned($this->payload())->assertOk();
        $eventId = WebhookEvent::query()->value('id');

        (new ProcessMetaLeadJob($eventId))->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', WebhookEvent::query()->find($eventId)->status);
    }

    public function test_an_event_for_another_page_is_ignored_when_a_page_is_configured(): void
    {
        app(SettingService::class)->set('integrations', 'meta_page_id', '999');
        Http::fake();

        $this->postSigned($this->payload())->assertOk();

        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_missing_access_token_is_a_permanent_configuration_failure(): void
    {
        config()->set('services.meta.access_token', '');
        Queue::fake();
        Http::fake();

        $this->postSigned($this->payload())->assertOk();
        (new ProcessMetaLeadJob(WebhookEvent::query()->value('id')))
            ->handle(app(MetaLeadIngestionService::class), app(MetaLeadFetcher::class));

        $this->assertSame('failed', WebhookEvent::query()->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_stored_webhook_payloads_contain_no_personal_data(): void
    {
        $this->fakeGraph();
        $this->postSigned($this->payload())->assertOk();

        $stored = json_encode(WebhookEvent::query()->firstOrFail()->payload);
        $this->assertStringNotContainsString('nora@example.com', $stored);
        $this->assertStringNotContainsString('111 222 3344', $stored);
    }
}
