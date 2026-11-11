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
                'credit_limit_policy' => 'warn',
                'base_currency' => 'EGP',
                'refund_segregation' => false,
            ]]);

        $settings = app(SettingService::class);
        $settings->set('tax', 'enabled', 'true');
        $settings->set('accounting', 'credit_limit_policy', 'block');
        $settings->set('accounting', 'refund_segregation', 'true');

        $this->getJson('/api/v1/feature-flags')->assertOk()
            ->assertJsonPath('data.tax_enabled', true)
            ->assertJsonPath('data.credit_limit_policy', 'block')
            ->assertJsonPath('data.refund_segregation', true);
    }
}
