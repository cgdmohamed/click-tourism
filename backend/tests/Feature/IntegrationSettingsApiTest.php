<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Admin screen API: enable flags, non-secret ids, webhook URLs, "configured" booleans only. */
class IntegrationSettingsApiTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        foreach (['settings.view', 'settings.update'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function actAsAdmin(): void
    {
        $this->user->givePermissionTo(['settings.view', 'settings.update']);
        Sanctum::actingAs($this->user);
    }

    public function test_everything_is_disabled_and_unconfigured_by_default(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/integrations/status')->assertOk()
            ->assertJsonPath('data.meta.enabled', false)
            ->assertJsonPath('data.whatsapp.enabled', false)
            ->assertJsonPath('data.whatsapp.outbound_enabled', false)
            ->assertJsonPath('data.meta.configured.app_secret', false)
            ->assertJsonPath('data.meta.configured.access_token', false)
            ->assertJsonPath('data.whatsapp.configured.verify_token', false)
            ->assertJsonPath('data.meta.webhook_verified_at', null)
            ->assertJsonPath('data.meta.webhook_url', url('/api/v1/webhooks/meta'))
            ->assertJsonPath('data.whatsapp.webhook_url', url('/api/v1/webhooks/whatsapp'));
    }

    public function test_secrets_are_reported_as_booleans_and_never_returned(): void
    {
        config()->set('services.meta.app_secret', 'SUPER-SECRET-APP');
        config()->set('services.meta.verify_token', 'SUPER-SECRET-VERIFY');
        config()->set('services.meta.access_token', 'SUPER-SECRET-ACCESS');
        config()->set('services.whatsapp.app_secret', 'SUPER-SECRET-WA');
        config()->set('services.whatsapp.access_token', 'SUPER-SECRET-WA-TOKEN');
        $this->actAsAdmin();

        $response = $this->getJson('/api/v1/integrations/status')->assertOk()
            ->assertJsonPath('data.meta.configured.app_secret', true)
            ->assertJsonPath('data.meta.configured.verify_token', true)
            ->assertJsonPath('data.meta.configured.access_token', true)
            ->assertJsonPath('data.whatsapp.configured.app_secret', true)
            ->assertJsonPath('data.whatsapp.configured.access_token', true)
            ->assertJsonPath('data.whatsapp.configured.verify_token', false);

        $this->assertStringNotContainsString('SUPER-SECRET', $response->getContent());
        $this->assertStringNotContainsString('SUPER-SECRET', $this->getJson('/api/v1/settings')->getContent());
    }

    public function test_an_admin_can_enable_the_integrations_and_set_non_secret_ids(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/v1/integrations/settings', [
            'meta_leads_enabled' => true,
            'meta_page_id' => '1234567890',
            'meta_default_branch_id' => $this->branch->public_id,
            'meta_form_branch_map' => json_encode(['9001' => $this->branch->public_id]),
            'whatsapp_enabled' => true,
            'whatsapp_phone_number_id' => '10987654321',
            'whatsapp_business_account_id' => '55512345',
            'meta_graph_version' => 'v21.0',
        ])->assertOk()
            ->assertJsonPath('data.meta.enabled', true)
            ->assertJsonPath('data.meta.page_id', '1234567890')
            ->assertJsonPath('data.meta.default_branch_id', $this->branch->public_id)
            ->assertJsonPath('data.whatsapp.enabled', true)
            ->assertJsonPath('data.whatsapp.outbound_enabled', false)
            ->assertJsonPath('data.whatsapp.phone_number_id', '10987654321');

        $this->assertTrue(app(SettingService::class)->bool('integrations', 'meta_leads_enabled'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'integrations.settings_changed')->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/v1/integrations/settings', ['meta_default_branch_id' => 'does-not-exist'])->assertStatus(422);
        $this->putJson('/api/v1/integrations/settings', ['meta_form_branch_map' => 'not json'])->assertStatus(422);
        $this->putJson('/api/v1/integrations/settings', ['meta_form_branch_map' => json_encode(['9001' => 'nope'])])->assertStatus(422);
        $this->putJson('/api/v1/integrations/settings', ['whatsapp_phone_number_id' => 'abc'])->assertStatus(422);
        $this->putJson('/api/v1/integrations/settings', ['meta_graph_version' => '21'])->assertStatus(422);
        // Secrets are not accepted here: unknown keys are ignored, never stored.
        $this->putJson('/api/v1/integrations/settings', ['app_secret' => 'x', 'access_token' => 'y'])->assertOk();
        $this->assertNull(Setting::query()->where('key', 'like', '%secret%')->orWhere('key', 'like', '%token%')->first());
    }

    public function test_the_generic_settings_endpoint_cannot_write_integration_settings(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'integrations', 'key' => 'meta_access_token', 'value' => 'leak', 'is_secret' => true],
        ]])->assertStatus(422);

        $this->assertFalse(Setting::query()->where('group', 'integrations')->where('key', 'meta_access_token')->exists());
    }

    public function test_permissions_are_enforced(): void
    {
        $this->getJson('/api/v1/integrations/status')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/integrations/status')->assertForbidden();
        $this->putJson('/api/v1/integrations/settings', ['meta_leads_enabled' => true])->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('settings.view');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/integrations/status')->assertOk();
        $this->putJson('/api/v1/integrations/settings', ['meta_leads_enabled' => true])->assertForbidden();
    }

    public function test_verification_status_and_event_counters_are_visible_after_a_handshake(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/v1/integrations/settings', ['whatsapp_enabled' => true])->assertOk();
        config()->set('services.whatsapp.verify_token', 'verify-me');

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=ok')->assertOk();

        $this->getJson('/api/v1/integrations/status')->assertOk()
            ->assertJsonPath('data.whatsapp.failed_events', 0)
            ->assertJsonStructure(['data' => ['whatsapp' => ['webhook_verified_at']]]);
        $this->assertNotNull($this->getJson('/api/v1/integrations/status')->json('data.whatsapp.webhook_verified_at'));
    }
}
