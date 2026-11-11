<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Login-user lifecycle (work order 01): directory, creation, role and branch
 * assignment, activation state. Users are global records — branch scope
 * applies to their assignments, never to the directory itself (ADR-0005).
 */
class UserService
{
    public function __construct(
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
    ) {}

    /** Serializable account shape shared by list, detail and mutation responses. */
    public function toUserAccount(User $user, bool $withPermissions = false): array
    {
        $account = [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'is_available_for_leads' => (bool) $user->is_available_for_leads,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'branches' => $user->branches->map->toApiArray()->values()->all(),
            'primary_branch_id' => $user->primaryBranch?->public_id,
            'employee' => $user->employee === null ? null : [
                'id' => $user->employee->public_id,
                'internal_code' => $user->employee->internal_code,
                'name_ar' => $user->employee->name_ar,
                'name_en' => $user->employee->name_en,
                'category' => $user->employee->category,
            ],
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];

        if ($withPermissions) {
            $account['permissions'] = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        }

        return $account;
    }

    /**
     * Create a login user. Roles/branches default to an empty assignment;
     * password hashing goes through the model cast.
     *
     * @param  array<string, mixed>  $data  Validated request payload.
     */
    public function create(array $data, User $actor): User
    {
        $roleNames = $data['roles'] ?? [];
        $branches = $this->resolveBranches($data['branch_ids'] ?? []);
        $primary = $this->resolvePrimary($data);

        $this->assertRoleAssignmentAllowed($actor, $roleNames);
        $this->assertBranchAssignmentAllowed($actor, $primary ? $branches->concat([$primary])->unique('id')->values() : $branches);

        $user = DB::transaction(function () use ($data, $actor, $roleNames, $branches, $primary) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'],
                'is_active' => true,
                'is_available_for_leads' => (bool) ($data['is_available_for_leads'] ?? true),
            ]);

            if ($roleNames !== []) {
                $user->syncRoles($roleNames);
            }

            $user->branches()->sync($branches->pluck('id')->all());

            if ($primary !== null) {
                $user->forceFill(['primary_branch_id' => $this->primaryWithin($user, $primary)->id])->save();
            }

            $this->audit->log('user.created', $user, null, $this->account($user), $actor->id);

            return $user;
        });

        return $user->load($this->accountRelations());
    }

    /**
     * Update a login user. `roles` and `branch_ids` replace the full sets
     * when present; a primary branch that falls out of the assignment is
     * cleared unless the request names a new one (which must be assigned).
     *
     * @param  array<string, mixed>  $data  Validated request payload.
     */
    public function update(User $user, array $data, User $actor): User
    {
        $roleNames = array_key_exists('roles', $data) ? $data['roles'] : null;
        $branches = array_key_exists('branch_ids', $data) ? $this->resolveBranches($data['branch_ids']) : null;
        $primaryProvided = array_key_exists('primary_branch_id', $data);
        $primary = $primaryProvided ? $this->resolvePrimary($data) : null;

        // Guards only cover the assignments actually being changed.
        if ($roleNames !== null) {
            $this->assertRoleAssignmentAllowed($actor, $roleNames);
            $this->assertAdminContinuity($user, $roleNames);
        }

        if ($branches !== null) {
            $this->assertBranchAssignmentAllowed($actor, $primary ? $branches->concat([$primary])->unique('id')->values() : $branches);
        } elseif ($primary !== null) {
            $this->assertBranchAssignmentAllowed($actor, collect([$primary]));
        }

        return DB::transaction(function () use ($user, $data, $actor, $roleNames, $branches, $primaryProvided, $primary) {
            $user = User::query()->findOrFail($user->id);
            $before = $this->account($user);

            $user->update(collect($data)->only(['name', 'email', 'password', 'locale', 'is_available_for_leads'])->all());

            if ($roleNames !== null) {
                $user->syncRoles($roleNames);
            }

            if ($branches !== null) {
                $user->branches()->sync($branches->pluck('id')->all());
            }

            if ($primaryProvided) {
                $user->forceFill(['primary_branch_id' => $primary === null ? null : $this->primaryWithin($user, $primary)->id])->save();
            } elseif ($branches !== null && $user->primary_branch_id !== null) {
                // The branch set changed without an explicit primary: keep it
                // only while it stays assigned.
                if (! in_array($user->primary_branch_id, $user->branches()->pluck('branches.id')->all(), true)) {
                    $user->forceFill(['primary_branch_id' => null])->save();
                }
            }

            $this->audit->log('user.updated', $user, $before, $this->account($user), $actor->id);

            return $user->load($this->accountRelations());
        });
    }

    /**
     * Deactivate a user. Their tokens stay on record — the `active`
     * middleware rejects them while the account is disabled.
     */
    public function deactivate(User $user, User $actor, string $reason): User
    {
        if ($user->id === $actor->id) {
            throw new DomainException('You cannot deactivate your own account.');
        }

        if ($user->hasRole('admin') && $this->activeAdminCount() <= 1) {
            throw new DomainException('The last active administrator cannot be deactivated or demoted.');
        }

        return DB::transaction(function () use ($user, $actor, $reason) {
            $user = User::query()->findOrFail($user->id);
            $before = $this->account($user);

            $user->forceFill(['is_active' => false])->save();

            $after = $this->account($user);
            $after['reason'] = $reason;

            $this->audit->log('user.deactivated', $user, $before, $after, $actor->id);

            return $user->load($this->accountRelations());
        });
    }

    public function activate(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor) {
            $user = User::query()->findOrFail($user->id);
            $before = $this->account($user);

            $user->forceFill(['is_active' => true])->save();

            $this->audit->log('user.activated', $user, $before, $this->account($user), $actor->id);

            return $user->load($this->accountRelations());
        });
    }

    /** @return list<string> */
    private function accountRelations(): array
    {
        return ['branches', 'roles', 'employee', 'primaryBranch'];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return $this->toUserAccount($user->refresh()->loadMissing($this->accountRelations()));
    }

    /** @return Collection<int, Branch> */
    private function resolveBranches(array $publicIds): Collection
    {
        if ($publicIds === []) {
            return collect();
        }

        return Branch::query()->whereIn('public_id', array_values($publicIds))->get();
    }

    /** The primary branch requested by the payload, or null when absent/null. */
    private function resolvePrimary(array $data): ?Branch
    {
        if (! isset($data['primary_branch_id']) || $data['primary_branch_id'] === null) {
            return null;
        }

        return Branch::query()->where('public_id', $data['primary_branch_id'])->first();
    }

    /** A primary branch must be one of the user's assigned branches. */
    private function primaryWithin(User $user, Branch $primary): Branch
    {
        if (! in_array($primary->id, $user->branches()->pluck('branches.id')->all(), true)) {
            throw new DomainException('The primary branch must be one of the assigned branches.');
        }

        return $primary;
    }

    /** Every role handed out must stay inside the actor's own privileges. */
    private function assertRoleAssignmentAllowed(User $actor, array $roleNames): void
    {
        if ($roleNames === []) {
            return;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roleNames)
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                if (! in_array($permission->name, $actorPermissions, true)) {
                    throw new AuthorizationException('You may not assign privileges you do not hold.');
                }
            }
        }
    }

    /**
     * Removing the admin role may never leave an installation without an
     * active administrator — the same lockout guard as deactivation.
     *
     * @param  list<string>  $newRoles
     */
    private function assertAdminContinuity(User $target, array $newRoles): void
    {
        if (in_array('admin', $newRoles, true) || ! $target->hasRole('admin')) {
            return;
        }

        if (! $target->is_active) {
            return; // an already-disabled admin carries no live access to remove
        }

        $otherActiveAdmins = (int) User::query()
            ->where('is_active', true)
            ->whereKeyNot($target->id)
            ->role('admin')
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new DomainException('The last active administrator cannot be deactivated or demoted.');
        }
    }

    /** Actors may only attach branches they can act in (ADR-0005). */
    private function assertBranchAssignmentAllowed(User $actor, Collection $branches): void
    {
        $allowed = $this->branchScope->allowedBranchIds($actor);

        foreach ($branches as $branch) {
            if (! in_array($branch->id, $allowed, true)) {
                throw new AuthorizationException('You may not assign a branch you are not assigned to.');
            }
        }
    }

    private function activeAdminCount(): int
    {
        return (int) User::query()->where('is_active', true)->role('admin')->count();
    }
}
