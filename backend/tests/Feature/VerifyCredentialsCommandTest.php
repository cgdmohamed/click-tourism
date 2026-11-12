<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VerifyCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_strong_unique_passwords(): void
    {
        User::factory()->create(['email' => 'owner@company.test', 'password' => Hash::make('Zx9!kQ-4mTw#pLv2')]);

        $this->artisan('erp:verify-credentials')->expectsOutputToContain('CREDENTIALS OK')->assertSuccessful();
    }

    public function test_fails_on_known_default_password(): void
    {
        User::factory()->create(['email' => 'owner@company.test', 'password' => Hash::make('change-me')]);

        $this->artisan('erp:verify-credentials')->expectsOutputToContain('known default password')->assertFailed();
    }

    public function test_fails_on_demo_account_outside_demo_mode(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('Zx9!kQ-4mTw#pLv2')]);

        $this->artisan('erp:verify-credentials')->expectsOutputToContain('demo-style')->assertFailed();
    }
}
