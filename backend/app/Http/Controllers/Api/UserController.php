<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\User;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    /** Active users of the current branch, searchable by name or email. Minimal fields only. */
    public function index(Request $request): JsonResponse
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
}
