<?php

namespace Tests\Feature;

use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Models\WebhookEvent;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(CrmSeeder::class);
    }

    private function payload(): string
    {
        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'business-account-1',
                'changes' => [[
                    'value' => [
                        'contacts' => [[
                            'wa_id' => '201000000000',
                            'profile' => ['name' => 'Hana Youssef'],
                        ]],
                        'messages' => [[
                            'id' => 'wamid.TEST-1',
                            'from' => '201000000000',
                            'text' => ['body' => 'I want a family package.'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function signedPost(string $payload, string $secret)
    {
        return $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
            ],
            $payload,
        );
    }

    public function test_the_verification_challenge_is_returned_for_the_configured_token(): void
    {
        config()->set('services.whatsapp.verify_token', 'verify-me');

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=challenge-123')
            ->assertOk()
            ->assertSee('challenge-123');
    }

    public function test_verification_is_rejected_for_a_wrong_token(): void
    {
        config()->set('services.whatsapp.verify_token', 'verify-me');

        $this->getJson('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=challenge-123')
            ->assertStatus(403)
            ->assertJsonPath('code', 'verification_failed');
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        config()->set('services.whatsapp.app_secret', 'app-secret');

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            ],
            $this->payload(),
        )->assertStatus(403)->assertJsonPath('code', 'invalid_signature');

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_signed_payload_creates_one_lead_and_replays_are_idempotent(): void
    {
        config()->set('services.whatsapp.app_secret', 'app-secret');

        $payload = $this->payload();

        $this->signedPost($payload, 'app-secret')
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertSame(1, Lead::query()->count());

        $lead = Lead::query()->firstOrFail();
        $this->assertSame('Hana Youssef', $lead->name);
        $this->assertSame('201000000000', $lead->phone);
        $this->assertSame('whatsapp', $lead->source?->code);
        $this->assertSame('wamid.TEST-1', $lead->external_id);

        $this->signedPost($payload, 'app-secret')
            ->assertOk()
            ->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(1, WebhookEvent::query()->count());
    }

    public function test_a_second_distinct_payload_is_processed_even_with_the_same_business_account_id(): void
    {
        config()->set('services.whatsapp.app_secret', 'app-secret');

        $first = $this->payload();
        $second = str_replace('wamid.TEST-1', 'wamid.TEST-2', $first);

        $this->signedPost($first, 'app-secret')->assertOk();
        $this->signedPost($second, 'app-secret')->assertOk();

        $this->assertSame(2, Lead::query()->count());
        $this->assertSame(2, WebhookEvent::query()->count());
    }
}
