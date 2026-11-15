<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** ADR-0032: a new user is invited by e-mail and chooses their own password. */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Permission::query()->firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'users.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create(['email' => 'boss@example.com']);
        $this->admin->givePermissionTo(['users.manage', 'users.view']);
        Sanctum::actingAs($this->admin);
    }

    private function create(array $over = [])
    {
        return $this->postJson('/api/v1/users', $over + ['name' => 'Nour', 'email' => 'nour@example.com', 'locale' => 'ar']);
    }

    private function newUser(): User
    {
        return User::query()->where('email', 'nour@example.com')->firstOrFail();
    }

    public function test_a_user_created_without_a_password_is_invited_by_e_mail(): void
    {
        Notification::fake();

        $this->create()->assertCreated()->assertJsonPath('data.invite_sent', true)->assertJsonPath('data.invite_pending', true);

        $user = $this->newUser();
        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use ($user) {
            $mail = $n->toMail($user);

            return str_contains($mail->actionUrl, '/reset-password?invite=1&token=') && str_contains($mail->subject, 'دعوة');
        });
        $this->assertNotNull($user->invite_sent_at);
        $this->postJson('/api/v1/auth/login', ['email' => 'nour@example.com', 'password' => 'anything-at-all-12'])->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.invited']);
    }

    public function test_without_a_working_mailer_a_password_is_required_and_nothing_is_created(): void
    {
        config(['mail.default' => 'log']);

        $this->create()->assertStatus(422)->assertJsonPath('code', 'mail_not_configured');

        $this->assertDatabaseMissing('users', ['email' => 'nour@example.com']);
    }

    public function test_a_typed_password_still_works_and_sends_no_invitation(): void
    {
        Notification::fake();

        $this->create(['password' => 'a-typed-passphrase-12'])->assertCreated()->assertJsonPath('data.invite_pending', false);

        Notification::assertNothingSent();
        $this->postJson('/api/v1/auth/login', ['email' => 'nour@example.com', 'password' => 'a-typed-passphrase-12'])->assertOk();
    }

    public function test_the_invitation_link_sets_the_password_and_works_for_a_week_but_once(): void
    {
        Notification::fake();
        $this->create()->assertCreated();
        $user = $this->newUser();
        $token = Password::broker('invites')->createToken($user);
        $this->travel(6)->days();

        $body = ['email' => 'nour@example.com', 'token' => $token, 'password' => 'my-own-passphrase-1', 'password_confirmation' => 'my-own-passphrase-1'];
        $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();

        $this->assertTrue(Hash::check('my-own-passphrase-1', $user->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'user.invite_accepted']);
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422);
    }

    public function test_an_invitation_expires_after_seven_days(): void
    {
        Notification::fake();
        $this->create()->assertCreated();
        $token = Password::broker('invites')->createToken($this->newUser());
        $this->travel(8)->days();

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'nour@example.com', 'token' => $token, 'password' => 'my-own-passphrase-1', 'password_confirmation' => 'my-own-passphrase-1'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_link');
    }

    public function test_a_reset_link_does_not_inherit_the_longer_invitation_life(): void
    {
        $user = User::factory()->create(['email' => 'maha@example.com']);
        $token = Password::createToken($user);
        $this->travel(2)->hours();

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'maha@example.com', 'token' => $token, 'password' => 'my-own-passphrase-1', 'password_confirmation' => 'my-own-passphrase-1'])
            ->assertStatus(422);
    }

    public function test_a_failing_mailer_still_creates_the_user_and_says_the_invite_was_not_sent(): void
    {
        Password::shouldReceive('broker')->with('invites')->andThrow(new \RuntimeException('smtp down'));

        $this->create()->assertCreated()->assertJsonPath('data.invite_sent', false);

        $this->assertDatabaseHas('users', ['email' => 'nour@example.com']);
        $this->assertNull($this->newUser()->invite_sent_at);
    }

    public function test_the_invitation_can_be_sent_again_only_while_it_is_pending(): void
    {
        Notification::fake();
        $this->create()->assertCreated();
        $user = $this->newUser();

        $this->postJson("/api/v1/users/{$user->public_id}/invite")->assertOk()->assertJsonPath('data.invite_pending', true);
        Notification::assertSentToTimes($user, UserInvitation::class, 2);

        $user->forceFill(['last_login_at' => now()])->save();
        $this->postJson("/api/v1/users/{$user->public_id}/invite")->assertStatus(422)->assertJsonPath('code', 'invite_not_pending');

        $typed = User::factory()->create();
        $this->postJson("/api/v1/users/{$typed->public_id}/invite")->assertStatus(422);
    }

    public function test_resending_needs_the_manage_permission_and_a_mailer(): void
    {
        Notification::fake();
        $this->create()->assertCreated();
        $user = $this->newUser();

        config(['mail.default' => 'log']);
        $this->postJson("/api/v1/users/{$user->public_id}/invite")->assertStatus(503);

        config(['mail.default' => 'smtp']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('users.view');
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$user->public_id}/invite")->assertStatus(403);
    }
}
