<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function index(RoleService $roles): JsonResponse
    {
        return $this->ok($roles->list());
    }

    /** Permission names are an API contract: additive changes only. */
    public function permissions(RoleService $roles): JsonResponse
    {
        return $this->ok($roles->permissionCatalog());
    }

    public function store(Request $request, RoleService $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $role = $roles->create($data, $request->user());

        return $this->created($roles->toRoleRecord($role->loadCount('users')));
    }

    public function update(Request $request, Role $role, RoleService $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id),
            ],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $role = $roles->update($role, $data, $request->user());

        return $this->ok($roles->toRoleRecord($role->loadCount('users')));
    }

    public function destroy(Request $request, Role $role, RoleService $roles): JsonResponse
    {
        $roles->delete($role, $request->user());

        return $this->ok(['message' => 'Deleted.']);
    }
}
