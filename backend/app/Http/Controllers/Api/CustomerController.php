<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Services\PartyProfileService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $customers = Customer::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['name_ar', 'name_en', 'phone', 'email'], $search))
            ->when($request->query('is_active') !== null && $request->query('is_active') !== '', fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->orderBy('name_ar')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $customers->getCollection()->transform(fn (Customer $customer) => $customer->toApiArray());

        return $this->paginated($customers);
    }

    public function show(Request $request, Customer $customer, PartyProfileService $profiles): JsonResponse
    {
        $this->authorizeBranchId($customer->branch_id);

        [$from, $to] = $this->profileRange($request);

        return $this->ok($profiles->customer($customer, $this->branch(), $from, $to));
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['individual', 'company'])],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'string'],
            'credit_limit_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $branch = $this->resolveBranch($data['branch_id'] ?? null);

        $customer = Customer::query()->create([
            'type' => $data['type'] ?? 'individual',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'branch_id' => $branch->id,
            'credit_limit_minor' => (int) ($data['credit_limit_minor'] ?? 0),
            'currency_code' => strtoupper($data['currency'] ?? $this->settings()->baseCurrency()),
            'is_active' => true,
        ]);

        $audit->log('customer.created', $customer, null, $customer->toApiArray(), $this->actorId());

        return $this->created($customer->toApiArray());
    }

    public function update(Request $request, Customer $customer, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($customer->branch_id);

        $data = $request->validate([
            'type' => ['nullable', Rule::in(['individual', 'company'])],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'credit_limit_minor' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = $customer->toApiArray();
        $customer->update($data);
        $audit->log('customer.updated', $customer, $before, $customer->toApiArray(), $this->actorId());

        return $this->ok($customer->refresh()->toApiArray());
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
