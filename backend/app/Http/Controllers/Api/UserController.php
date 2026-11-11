<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\UserService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends ApiController
{
    /** Management directory (requires users.view): global, filterable, full accounts. */
    public function index(Request $request, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'role' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? 'all';

        $directory = User::query()
            ->with(['branches', 'roles', 'employee', 'primaryBranch'])
            ->when(Like::term($data['search'] ?? null), fn ($query, $search) => Like::contains($query, ['name', 'email'], $search))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($data['role'] ?? null, fn ($query, $role) => $query->whereHas('roles', fn ($q) => $q->where('roles.name', $role)))
            ->when($data['branch_id'] ?? null, fn ($query, $branch) => $query->whereHas('branches', fn ($q) => $q->where('branches.public_id', $branch)))
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) ($data['per_page'] ?? 50))));

        $directory->getCollection()->transform(fn (User $user) => $users->toUserAccount($user));

        return $this->paginated($directory);
    }

    /** Active users of the current branch, searchable by name or email. Minimal fields only. */
    public function assignable(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $users = User::query()
            ->where('is_active', true)
            ->when($branch, fn ($query) => $query->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id)))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains($query, ['name', 'email'], $search))
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $users->getCollection()->transform(fn (User $user) => [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return $this->paginated($users);
    }

    public function store(Request $request, UserService $users): JsonResponse
    {
        $data = $this->validated($request);

        $user = $users->create($data, $request->user());

        return $this->created($users->toUserAccount($user));
    }

    public function show(User $user, UserService $users): JsonResponse
    {
        return $this->ok($users->toUserAccount($user->load(['branches', 'roles', 'employee', 'primaryBranch', 'permissions', 'roles.permissions']), true));
    }

    public function update(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $this->validated($request, $user);

        // Keep the actor's own token alive when they change their own
        // password here; every other token of the target is revoked.
        $current = $request->user()->currentAccessToken();
        $keepTokenId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user = $users->update($user, $data, $request->user(), $keepTokenId);

        return $this->ok($users->toUserAccount($user));
    }

    public function deactivate(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user = $users->deactivate($user, $request->user(), $data['reason']);

        return $this->ok($users->toUserAccount($user));
    }

    public function activate(Request $request, User $user, UserService $users): JsonResponse
    {
        $user = $users->activate($user, $request->user());

        return $this->ok($users->toUserAccount($user));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [
                $user ? 'sometimes' : 'required',
                'email',
                'max:255',
                $user ? Rule::unique('users', 'email')->ignore($user->id) : 'unique:users,email',
            ],
            'password' => [$user ? 'sometimes' : 'required', 'string', 'min:12'],
            'locale' => [$user ? 'sometimes' : 'required', Rule::in(['ar', 'en'])],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['string', Rule::exists('branches', 'public_id')],
            'primary_branch_id' => ['sometimes', 'nullable', 'string', Rule::exists('branches', 'public_id')],
            'is_available_for_leads' => ['sometimes', 'boolean'],
        ]);
    }
}
