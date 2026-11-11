<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Parties\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * User management endpoints (work order 01): directory, lifecycle, guards and
 * the privilege-escalation policy. All state changes must be audited.
 */
class UserManagementTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->otherBranch = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo($permissions);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_admin_lists_searches_and_filters_the_user_directory(): void
    {
        $agent = User::factory()->create(['name' => 'Searchable Agent', 'email' => 'agent@example.test']);
        $agent->branches()->attach($this->otherBranch->id);
        $agent->assignRole('sales');

        Employee::query()->create([
            'internal_code' => 'EMP-01', 'name_ar' => 'موظف الوكيل', 'name_en' => 'Agent Employee',
            'category' => 'staff', 'branch_id' => $this->branch->id, 'user_id' => $agent->id, 'is_active' => true,
        ]);

        User::factory()->inactive()->create(['name' => 'Sleeping Agent']);

        $response = $this->getJson('/api/v1/users')->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total', 'per_page']]);

        // Default status=all includes inactive users.
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Searchable Agent', $names);
        $this->assertContains('Sleeping Agent', $names);

        $this->getJson('/api/v1/users?search=Searchable')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $agent->public_id)
            ->assertJsonPath('data.0.employee.internal_code', 'EMP-01');

        $this->getJson('/api/v1/users?search=agent@example.test')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/users?role=sales')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $agent->public_id);

        $this->getJson('/api/v1/users?branch_id='.$this->otherBranch->public_id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.branches.0.code', 'ALX');

        $this->getJson('/api/v1/users?status=inactive')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sleeping Agent');

        $activeNames = collect($this->getJson('/api/v1/users?status=active')->json('data'))->pluck('name');
        $this->assertFalse($activeNames->contains('Sleeping Agent'));

        $this->getJson('/api/v1/users?search=')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_the_directory_requires_users_view_and_creation_requires_users_manage(): void
    {
        $viewer = User::factory()->create();
        $this->actAs($viewer);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [
            'name' => 'No Access', 'email' => 'no-access@example.test',
            'password' => 'long-enough-pass', 'locale' => 'ar',
        ])->assertForbidden();
    }

    public function test_admin_creates_a_user_with_roles_branches_and_primary_branch(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Cashier',
            'email' => 'cashier@example.test',
            'password' => 'correct-horse-battery',
            'locale' => 'ar',
            'roles' => ['cashier'],
            'branch_ids' => [$this->branch->public_id],
            'primary_branch_id' => $this->branch->public_id,
        ])->assertCreated();

        $response->assertJsonPath('data.email', 'cashier@example.test')
            ->assertJsonPath('data.roles.0', 'cashier')
            ->assertJsonPath('data.primary_branch_id', $this->branch->public_id)
            ->assertJsonPath('data.is_active', true);

        $user = User::query()->where('email', 'cashier@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertSame([$this->branch->id], $user->branchIds());
        $this->assertSame($this->branch->id, $user->primary_branch_id);

        $this->assertNotEmpty(
            AuditEvent::query()->where('action', 'user.created')->where('entity_id', $user->id)->get()
        );
    }

    public function test_user_creation_validates_the_payload(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $payload = ['name' => 'Valid', 'email' => 'fresh@example.test', 'password' => 'long-enough-pass', 'locale' => 'ar'];

        $this->postJson('/api/v1/users', array_merge($payload, ['email' => 'taken@example.test']))->assertStatus(422);
        $this->postJson('/api/v1/users', array_merge($payload, ['password' => 'short']))->assertStatus(422);
        $this->postJson('/api/v1/users', array_merge($payload, ['locale' => 'fr']))->assertStatus(422);
        $this->postJson('/api/v1/users', array_merge($payload, ['roles' => ['nonexistent_role']]))->assertStatus(422);
        $this->postJson('/api/v1/users', array_merge($payload, ['primary_branch_id' => $this->branch->public_id]))->assertStatus(422);

        $this->assertSame(0, AuditEvent::query()->where('action', 'user.created')->count());
    }

    public function test_admin_updates_a_user_and_the_primary_branch_follows_the_assignment(): void
    {
        $target = User::factory()->create();
        $target->assignRole('sales');
        $target->branches()->sync([$this->branch->id]);
        $target->forceFill(['primary_branch_id' => $this->branch->id])->save();

        $this->patchJson('/api/v1/users/'.$target->public_id, [
            'name' => 'Renamed',
            'email' => 'renamed@example.test',
            'roles' => ['viewer'],
            'branch_ids' => [$this->otherBranch->public_id],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $target = $target->fresh();
        $this->assertTrue($target->hasRole('viewer'));
        $this->assertFalse($target->hasRole('sales'));
        $this->assertSame('renamed@example.test', $target->email);
        $this->assertSame([$this->otherBranch->id], $target->branchIds());
        $this->assertNull($target->primary_branch_id); // primary cleared when its branch was removed

        $this->patchJson('/api/v1/users/'.$target->public_id, [
            'primary_branch_id' => $this->otherBranch->public_id,
        ])->assertOk()->assertJsonPath('data.primary_branch_id', $this->otherBranch->public_id);

        $this->patchJson('/api/v1/users/'.$target->public_id, [
            'primary_branch_id' => $this->branch->public_id,
        ])->assertStatus(422)->assertJsonPath('message', 'The primary branch must be one of the assigned branches.');

        $this->assertNotEmpty(
            AuditEvent::query()->where('action', 'user.updated')->where('entity_id', $target->id)->get()
        );
    }

    public function test_deactivation_requires_a_reason_and_blocks_existing_tokens(): void
    {
        $target = User::factory()->create();
        $token = $target->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/users/'.$target->public_id.'/deactivate', [])->assertStatus(422);

        $this->postJson('/api/v1/users/'.$target->public_id.'/deactivate', ['reason' => 'Left the company'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(1, $target->tokens()->count()); // tokens are kept; the active middleware rejects them

        $this->assertNotEmpty(
            AuditEvent::query()->where('action', 'user.deactivated')->where('entity_id', $target->id)->get()
        );

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_self_deactivation_and_last_admin_deactivation_are_refused(): void
    {
        $this->postJson('/api/v1/users/'.$this->user->public_id.'/deactivate', ['reason' => 'Bye'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        // A non-admin manager with users.manage may not remove the last admin.
        $delegate = User::factory()->create();
        $this->grant($delegate, ['users.view', 'users.manage']);
        $this->actAs($delegate);

        $this->postJson('/api/v1/users/'.$this->user->public_id.'/deactivate', ['reason' => 'Downgrade'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You may not manage an account with privileges you do not hold.');

        // Even with multiple administrators, a less privileged delegate
        // cannot disable administrators or reset their credentials.
        User::factory()->create()->assignRole('admin');

        $this->postJson('/api/v1/users/'.$this->user->public_id.'/deactivate', ['reason' => 'Downgrade'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You may not manage an account with privileges you do not hold.');
    }

    public function test_the_last_active_administrator_cannot_be_demoted(): void
    {
        // Self-demote: the actor is the only active administrator.
        $this->patchJson('/api/v1/users/'.$this->user->public_id, ['roles' => ['viewer']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The last active administrator cannot be deactivated or demoted.');

        $this->assertTrue($this->user->fresh()->hasRole('admin'));

        // A second active administrator makes the demotion safe.
        User::factory()->create()->assignRole('admin');

        $this->patchJson('/api/v1/users/'.$this->user->public_id, ['roles' => ['viewer']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['viewer']);

        $this->assertFalse($this->user->fresh()->hasRole('admin'));
    }

    public function test_a_deactivated_user_can_be_reactivated(): void
    {
        $target = User::factory()->inactive()->create();

        $this->postJson('/api/v1/users/'.$target->public_id.'/activate')->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($target->fresh()->is_active);
        $this->assertNotEmpty(
            AuditEvent::query()->where('action', 'user.activated')->where('entity_id', $target->id)->get()
        );
    }

    public function test_a_manager_cannot_assign_privileges_they_do_not_hold(): void
    {
        $manager = User::factory()->create();
        $manager->branches()->attach($this->branch->id);
        $this->grant($manager, ['users.view', 'users.manage', 'customers.view']);
        $this->actAs($manager);

        $this->postJson('/api/v1/users', [
            'name' => 'Escalated', 'email' => 'escalated@example.test',
            'password' => 'long-enough-pass', 'locale' => 'ar',
            'roles' => ['accountant'],
        ])->assertStatus(403)->assertJsonPath('message', 'You may not assign privileges you do not hold.');

        $this->assertSame(0, AuditEvent::query()->where('action', 'user.created')->count());

        // A role whose permissions stay inside the actor's own set is allowed.
        $light = Role::query()->create(['name' => 'light_agent', 'guard_name' => 'web']);
        $light->syncPermissions(['customers.view']);

        $this->postJson('/api/v1/users', [
            'name' => 'Scoped', 'email' => 'scoped@example.test',
            'password' => 'long-enough-pass', 'locale' => 'ar',
            'roles' => ['light_agent'],
            'branch_ids' => [$this->branch->public_id],
        ])->assertCreated()->assertJsonPath('data.roles.0', 'light_agent');
    }

    public function test_delegated_manager_cannot_take_over_an_admin_via_password_reset(): void
    {
        $manager = User::factory()->create();
        $manager->branches()->attach($this->branch->id);
        $this->grant($manager, ['users.view', 'users.manage']);
        $oldHash = $this->user->password;
        $this->actAs($manager);

        $this->patchJson('/api/v1/users/'.$this->user->public_id, [
            'password' => 'attacker-new-password-123',
        ])->assertForbidden()->assertJsonPath(
            'message', 'You may not manage an account with privileges you do not hold.'
        );

        $this->assertSame($oldHash, $this->user->fresh()->password);
    }

    public function test_unknown_branch_identifiers_are_rejected_instead_of_silently_ignored(): void
    {
        $payload = [
            'name' => 'Unknown Branch User',
            'email' => 'unknown-branch@example.test',
            'password' => 'long-enough-password',
            'locale' => 'ar',
            'branch_ids' => [$this->branch->public_id, '01AAAAAAAAAAAAAAAAAAAAAAAA'],
        ];

        $this->postJson('/api/v1/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more requested branches do not exist.');

        $payload['branch_ids'] = [$this->branch->public_id];
        $payload['primary_branch_id'] = '01AAAAAAAAAAAAAAAAAAAAAAAA';
        $this->postJson('/api/v1/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Requested primary branch does not exist.');

        $this->assertDatabaseMissing('users', ['email' => 'unknown-branch@example.test']);
    }

    public function test_a_manager_cannot_assign_a_branch_they_are_not_assigned_to(): void
    {
        $manager = User::factory()->create();
        $manager->branches()->attach($this->branch->id);
        $this->grant($manager, ['users.view', 'users.manage']);
        $this->actAs($manager);

        $this->postJson('/api/v1/users', [
            'name' => 'Branch Escalation', 'email' => 'branch-escalation@example.test',
            'password' => 'long-enough-pass', 'locale' => 'ar',
            'branch_ids' => [$this->otherBranch->public_id],
        ])->assertStatus(403)->assertJsonPath('message', 'You may not assign a branch you are not assigned to.');

        $this->assertSame(0, AuditEvent::query()->where('action', 'user.created')->count());

        $this->postJson('/api/v1/users', [
            'name' => 'Own Branch', 'email' => 'own-branch@example.test',
            'password' => 'long-enough-pass', 'locale' => 'ar',
            'branch_ids' => [$this->branch->public_id],
            'primary_branch_id' => $this->branch->public_id,
        ])->assertCreated();
    }

    public function test_assigning_a_role_via_the_api_takes_effect_immediately(): void
    {
        $agent = User::factory()->create();
        $agent->branches()->attach($this->branch->id);

        $this->patchJson('/api/v1/users/'.$agent->public_id, ['roles' => ['viewer']])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'viewer');

        $this->actAs($agent->fresh());

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_user_can_update_their_locale(): void
    {
        $this->patchJson('/api/v1/auth/me', ['locale' => 'en'])->assertOk()
            ->assertJsonPath('data.locale', 'en');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.locale', 'en');

        $this->assertNotEmpty(AuditEvent::query()->where('action', 'user.profile_updated')->get());
    }

    public function test_a_password_change_requires_the_current_password(): void
    {
        $this->patchJson('/api/v1/auth/me', ['password' => 'new-password-1234'])->assertStatus(422);

        $this->patchJson('/api/v1/auth/me', ['current_password' => 'wrong-password', 'password' => 'new-password-1234'])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

        $this->assertDatabaseMissing('audit_events', ['action' => 'user.password_changed']);
    }

    public function test_a_password_change_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        $kept = $this->user->createToken('kept')->plainTextToken;
        $revoked = $this->user->createToken('revoked')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$kept)
            ->patchJson('/api/v1/auth/me', ['current_password' => 'password', 'password' => 'new-password-1234'])
            ->assertOk();

        $this->assertNotEmpty(AuditEvent::query()->where('action', 'user.password_changed')->get());
        $this->assertSame(1, $this->user->tokens()->count());

        // The sanctum guard caches the authenticated user between requests of
        // the same test, so forget it before re-authenticating with a token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$kept)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$revoked)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
