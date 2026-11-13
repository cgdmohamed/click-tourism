<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/feature-flags')->assertUnauthorized();
    }

    public function test_any_authenticated_user_without_permissions_reads_flags_that_follow_settings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Same user cannot read the full settings list.
        $this->getJson('/api/v1/settings')->assertForbidden();

        $this->getJson('/api/v1/feature-flags')->assertOk()
            ->assertExactJson(['data' => [
                'tax_enabled' => false,
                'tax_default_rate' => '0',
                'tax_inclusive' => false,
                'credit_limit_policy' => 'warn',
                'base_currency' => 'EGP',
                'refund_segregation' => false,
                'mcp_enabled' => false,
                'assistant_enabled' => false,
                'assistant_provider' => null,
                'assistant_redact_pii' => true,
                'assistant_privacy_url' => '',
            ]]);

        $settings = app(SettingService::class);
        $settings->set('tax', 'enabled', 'true');
        $settings->set('tax', 'default_rate', '15');
        $settings->set('accounting', 'credit_limit_policy', 'block');
        $settings->set('accounting', 'refund_segregation', 'true');
        $settings->set('mcp', 'enabled', 'true');

        $this->getJson('/api/v1/feature-flags')->assertOk()
            ->assertJsonPath('data.tax_enabled', true)
            ->assertJsonPath('data.tax_default_rate', '15')
            ->assertJsonPath('data.credit_limit_policy', 'block')
            ->assertJsonPath('data.refund_segregation', true)
            ->assertJsonPath('data.mcp_enabled', true);
    }
}
