<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = app(BranchScope::class)->allowedBranchIds($user);

        $branches = Branch::query()
            ->whereIn('id', $ids)
            ->orderBy('code')
            ->get()
            ->map->toApiArray()
            ->values();

        return $this->ok($branches);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'accounting_mode' => ['nullable', 'in:dimension,customer,independent'],
        ]);

        $branch = Branch::query()->create([
            'code' => strtoupper($data['code']),
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'accounting_mode' => $data['accounting_mode'] ?? 'dimension',
            'is_active' => true,
        ]);

        $request->user()->branches()->syncWithoutDetaching([$branch->id]);
        $audit->log('branch.created', $branch, null, $branch->toApiArray(), $this->actorId());

        return $this->created($branch->toApiArray());
    }
}
