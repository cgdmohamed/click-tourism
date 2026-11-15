<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** ADR-0031: reset a forgotten password with an e-mailed, single-use link. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a-brand-new-passphrase-1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
    }

    private function user(array $over = []): User
    {
        return User::factory()->create($over + ['email' => 'maha@example.com', 'is_active' => true]);
    }

    public function test_the_login_page_learns_whether_reset_is_available(): void
    {
        $this->getJson('/api/v1/branding')->assertJsonPath('data.password_reset_available', true);
        config(['mail.default' => 'log']);
        $this->getJson('/api/v1/branding')->assertJsonPath('data.password_reset_available', false);
    }

    public function test_a_known_account_gets_a_link_to_the_app(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'maha@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password?token=') && str_contains($url, urlencode('maha@example.com'));
        });
    }

    public function test_the_answer_is_the_same_for_an_unknown_or_disabled_account(): void
    {
        Notification::fake();
        $this->user(['email' => 'off@example.com', 'is_active' => false]);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);
        $off = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'off@example.com']);

        $this->assertSame($known->status(), $off->status());
        $this->assertSame($known->json(), $off->json());
        Notification::assertNothingSent();
    }

    public function test_a_mail_failure_gives_the_same_answer_and_logs_no_address(): void
    {
        $this->user();
        Log::spy();
        Password::shouldReceive('sendResetLink')->andThrow(new \RuntimeException('smtp down for maha@example.com'));

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'maha@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $known->status());
        $this->assertSame($unknown->status(), $known->status());
        $this->assertSame($unknown->json(), $known->json());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'password_reset.mail_failed'
            && ! str_contains(json_encode($context), 'maha@example.com'));
    }

    public function test_without_a_real_mailer_it_says_so_instead_of_pretending(): void
    {
        config(['mail.default' => 'log']);
        $this->user();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'maha@example.com'])
            ->assertStatus(503)->assertJsonPath('code', 'password_reset_unavailable');
    }

    public function test_the_link_sets_a_new_password_once_and_ends_every_session(): void
    {
        $user = $this->user();
        $user->createToken('old-device');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_reset_by_link']);
        $this->postJson('/api/v1/auth/login', ['email' => 'maha@example.com', 'password' => self::NEW_PASSWORD])->assertOk();

        // The same link a second time is dead.
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => 'another-long-passphrase-2', 'password_confirmation' => 'another-long-passphrase-2'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_link');
    }

    public function test_a_wrong_token_a_short_password_or_a_mismatch_changes_nothing(): void
    {
        $user = $this->user();
        $old = $user->password;
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => 'wrong', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-passphrase-9'])->assertStatus(422);

        $this->assertSame($old, $user->fresh()->password);
    }

    public function test_a_disabled_account_cannot_be_reset_with_a_valid_link(): void
    {
        $user = $this->user(['is_active' => false]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertStatus(422);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_link');
    }

    public function test_asking_for_links_is_throttled(): void
    {
        Notification::fake();
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'maha@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'maha@example.com'])->assertStatus(429);
    }
}
