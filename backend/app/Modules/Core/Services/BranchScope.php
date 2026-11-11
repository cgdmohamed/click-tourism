<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Server-side branch scoping. The client may send X-Branch-Id but it is only
 * accepted when the user is assigned to that branch (ADR-0005).
 */
class BranchScope
{
    /** @return int[] */
    public function allowedBranchIds(User $user): array
    {
        if ($user->can('branches.view_all')) {
            return Branch::query()->pluck('id')->all();
        }

        return $user->branchIds();
    }

    public function resolve(User $user): ?Branch
    {
        $requested = request()->header('X-Branch-Id');
        $allowed = $this->allowedBranchIds($user);

        if ($requested) {
            $branch = Branch::query()->where('public_id', $requested)->first();
            if (! $branch || ! in_array($branch->id, $allowed, true)) {
                throw new AuthorizationException('You are not assigned to the requested branch.');
            }

            return $branch;
        }

        // Without a header, prefer the user's own primary branch while it is
        // still assigned; otherwise fall back to the lowest allowed id.
        if ($user->primary_branch_id !== null && in_array($user->primary_branch_id, $allowed, true)) {
            $primary = Branch::query()->find($user->primary_branch_id);
            if ($primary) {
                return $primary;
            }
        }

        return Branch::query()->whereIn('id', $allowed)->orderBy('id')->first();
    }

    public function assertBranch(User $user, Branch $branch): void
    {
        if (! in_array($branch->id, $this->allowedBranchIds($user), true)) {
            throw new AuthorizationException('You are not assigned to this branch.');
        }
    }
}
