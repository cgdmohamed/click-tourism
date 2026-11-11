<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    protected function branch(): ?Branch
    {
        $user = request()->user();
        if (! $user) {
            return null;
        }

        return app(BranchScope::class)->resolve($user);
    }

    protected function branchOrFail(): Branch
    {
        $branch = $this->branch();
        if (! $branch) {
            throw new AuthorizationException('No branch is assigned to this user.');
        }

        return $branch;
    }

    /** Deny access to a record that belongs to a branch the user is not assigned to (ADR-0005). */
    protected function authorizeBranchId(?int $branchId): void
    {
        $user = request()->user();

        if ($branchId === null || ! $user) {
            return;
        }

        if (! in_array($branchId, app(BranchScope::class)->allowedBranchIds($user), true)) {
            throw new AuthorizationException('You are not assigned to this record\'s branch.');
        }
    }

    /** Deny creating into an explicitly requested branch the user is not assigned to (ADR-0005). */
    protected function authorizeBranchChoice(Branch $branch): void
    {
        $user = request()->user();

        if ($user && ! in_array($branch->id, app(BranchScope::class)->allowedBranchIds($user), true)) {
            throw new AuthorizationException('You are not assigned to the requested branch.');
        }
    }

    protected function actorId(): ?int
    {
        return request()->user()?->id;
    }

    protected function settings(): SettingService
    {
        return app(SettingService::class);
    }

    /**
     * Statement window for profile endpoints. Defaults to the current year.
     *
     * @return array{0: string, 1: string}
     */
    protected function profileRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            $data['from'] ?? now()->startOfYear()->toDateString(),
            $data['to'] ?? now()->toDateString(),
        ];
    }

    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        return ApiResponse::data($data, $status);
    }

    protected function created(mixed $data): JsonResponse
    {
        return ApiResponse::data($data, 201);
    }

    protected function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return ApiResponse::collection($paginator);
    }
}
