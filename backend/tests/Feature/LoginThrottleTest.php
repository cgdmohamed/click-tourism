<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every route with an inline `throttle:N,M` used to share ONE counter (keyed by IP only), so page loads
 * (`demo/status`) and other limited routes counted against the 10-per-minute login limit and could lock
 * people out. Each limited route now has its own prefix.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_loads_and_other_limited_routes_do_not_use_up_the_login_limit(): void
    {
        $user = User::factory()->create(['email' => 'throttle@example.com', 'password' => Hash::make('a-long-test-password-1')]);

        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/v1/demo/status')->assertOk();
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-long-test-password-1', 'device_name' => 'test'])->assertOk();
    }

    public function test_failed_logins_are_still_limited_to_ten_a_minute(): void
    {
        $user = User::factory()->create(['email' => 'brute@example.com', 'password' => Hash::make('a-long-test-password-1')]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password-'.$i, 'device_name' => 'test'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password-x', 'device_name' => 'test'])->assertStatus(429);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-long-test-password-1', 'device_name' => 'test'])->assertStatus(429);
    }

    public function test_every_inline_throttle_has_its_own_counter_prefix(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertSame(0, preg_match("/throttle:\\d+,\\d+['\"]/", $routes), 'an inline throttle without a prefix shares one counter with every other one');
    }
}
