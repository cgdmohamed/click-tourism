<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role directory and custom role lifecycle (work order 01). The system
 * roles seeded by RolePermissionSeeder are re-synced by `roles:sync` on
 * every upgrade and are therefore read-only here; only installation-defined
 * roles may be changed or deleted.
 */
class RoleService
{
    /** Roles owned by the product and re-applied by `roles:sync`. */
    public const SYSTEM_ROLES = [
        'admin', 'accountant', 'senior_accountant', 'sales', 'viewer',
        'finance_manager', 'reservations', 'supervisor', 'cashier',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        // Spatie resolves the `users` relation through the default auth guard;
        // once the request pipeline switches it to the sanctum guard (which has
        // no user provider) `withCount` fails, so counts come from the pivot
        // table instead.
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->groupBy('role_id')
            ->selectRaw('role_id, COUNT(*) AS users_count')
            ->pluck('users_count', 'role_id');

        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->toRoleRecord($role, $counts))
            ->all();
    }

    /** @return list<string> */
    public function permissionCatalog(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>|null  $usersCount  Optional role_id => user count map.
     * @return array<string, mixed>
     */
    public function toRoleRecord(Role $role, ?Collection $usersCount = null): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'is_system' => in_array($role->name, self::SYSTEM_ROLES, true),
            'users_count' => (int) ($usersCount !== null
                ? $usersCount->get($role->id, 0)
                : ($role->users_count ?? $role->users()->count())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated request payload.
     */
    public function create(array $data, User $actor): Role
    {
        $this->assertPermissionSubset($actor, $data['permissions']);

        return DB::transaction(function () use ($data, $actor) {
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions']);

            $this->audit->log('role.created', $role, null, $this->toRoleRecord($role), $actor->id);

            return $role;
        })->load('permissions');
    }

    /**
     * @param  array<string, mixed>  $data  Validated request payload.
     */
    public function update(Role $role, array $data, User $actor): Role
    {
        $this->assertNotSystem($role);

        if (isset($data['permissions'])) {
            $this->assertPermissionSubset($actor, $data['permissions']);
        }

        return DB::transaction(function () use ($role, $data, $actor) {
            $role = Role::query()->findOrFail($role->id);
            $before = $this->toRoleRecord($role->load('permissions'));

            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            $this->audit->log('role.updated', $role, $before, $this->toRoleRecord($role->refresh()->load('permissions')), $actor->id);

            return $role->load('permissions');
        });
    }

    public function delete(Role $role, User $actor): void
    {
        $this->assertNotSystem($role);

        if ($role->users()->count() > 0) {
            throw new DomainException('This role is assigned to users and cannot be deleted.');
        }

        DB::transaction(function () use ($role, $actor) {
            $role = Role::query()->findOrFail($role->id);
            $before = $this->toRoleRecord($role->load('permissions'));

            $this->audit->log('role.deleted', $role, $before, null, $actor->id);

            $role->delete();
        });
    }

    /** A role may never carry a permission its author does not hold. */
    private function assertPermissionSubset(User $actor, array $permissions): void
    {
        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();

        foreach ($permissions as $permission) {
            if (! in_array($permission, $actorPermissions, true)) {
                throw new AuthorizationException('You may not assign privileges you do not hold.');
            }
        }
    }

    private function assertNotSystem(Role $role): void
    {
        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            throw new DomainException('This role is a system role and cannot be modified or deleted.');
        }
    }
}
