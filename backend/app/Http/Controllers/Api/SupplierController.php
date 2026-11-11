<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyProfileService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $suppliers = Supplier::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['name_ar', 'name_en', 'phone'], $search))
            ->orderBy('name_ar')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $suppliers->getCollection()->transform(fn (Supplier $supplier) => $supplier->toApiArray());

        return $this->paginated($suppliers);
    }

    public function show(Request $request, Supplier $supplier, PartyProfileService $profiles): JsonResponse
    {
        $this->authorizeBranchId($supplier->branch_id);

        [$from, $to] = $this->profileRange($request);

        return $this->ok($profiles->supplier($supplier, $this->branch(), $from, $to));
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:30'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'settlement_currency' => ['nullable', 'string', 'size:3'],
            'branch_id' => ['nullable', 'string'],
        ]);

        $branch = $this->resolveBranch($data['branch_id'] ?? null);

        $supplier = Supplier::query()->create([
            'category' => $data['category'] ?? 'other',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'settlement_currency' => strtoupper($data['settlement_currency'] ?? $this->settings()->baseCurrency()),
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $audit->log('supplier.created', $supplier, null, $supplier->toApiArray(), $this->actorId());

        return $this->created($supplier->toApiArray());
    }

    public function update(Request $request, Supplier $supplier, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($supplier->branch_id);

        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:30'],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'settlement_currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = $supplier->toApiArray();
        $supplier->update($data);
        $audit->log('supplier.updated', $supplier, $before, $supplier->toApiArray(), $this->actorId());

        return $this->ok($supplier->refresh()->toApiArray());
    }

    private function resolveBranch(?string $publicId): Branch
    {
        if ($publicId) {
            $branch = Branch::query()->where('public_id', $publicId)->first();
            if ($branch) {
                $this->authorizeBranchChoice($branch);

                return $branch;
            }
        }

        return $this->branchOrFail();
    }
}
