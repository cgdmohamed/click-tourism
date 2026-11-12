<?php

namespace App\Http\Controllers\Api;

use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionRuleService;
use App\Modules\Core\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionRuleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rules = CommissionRule::query()->with('branch', 'supersedes')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('beneficiary_type'), fn ($q, $type) => $q->where('beneficiary_type', $type))
            ->when($request->query('code'), fn ($q, $code) => $q->where('code', strtoupper((string) $code)))
            ->orderBy('code')->orderByDesc('version')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $rules->getCollection()->transform(fn (CommissionRule $rule) => $rule->toApiArray());

        return $this->paginated($rules);
    }

    public function show(CommissionRule $commissionRule): JsonResponse
    {
        return $this->ok($commissionRule->load('branch', 'supersedes')->toApiArray());
    }

    public function store(Request $request, CommissionRuleService $rules): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeRuleBranch($data);

        return $this->created($rules->create($data, $this->actorId())->toApiArray());
    }

    public function update(Request $request, CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        $data = $this->validated($request, partial: true);
        $this->authorizeRuleBranch($data);

        return $this->ok($rules->update($commissionRule, $data, $this->actorId())->toApiArray());
    }

    public function destroy(CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        $rules->destroy($commissionRule, $this->actorId());

        return $this->ok(['deleted' => true]);
    }

    public function newVersion(CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        return $this->created($rules->newVersion($commissionRule, $this->actorId())->toApiArray());
    }

    public function submit(CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        return $this->ok($rules->submit($commissionRule, $this->actorId())->toApiArray());
    }

    public function approve(CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        return $this->ok($rules->approve($commissionRule, $this->actorId())->toApiArray());
    }

    public function reject(Request $request, CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($rules->reject($commissionRule, $data['reason'], $this->actorId())->toApiArray());
    }

    public function retire(CommissionRule $commissionRule, CommissionRuleService $rules): JsonResponse
    {
        return $this->ok($rules->retire($commissionRule, $this->actorId())->toApiArray());
    }

    /** @param array<string, mixed> $data */
    private function authorizeRuleBranch(array $data): void
    {
        if (! empty($data['branch_id'])) {
            $branch = Branch::query()->where('public_id', $data['branch_id'])->first();
            if ($branch) {
                $this->authorizeBranchChoice($branch);
            }
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => $partial ? ['prohibited'] : ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'beneficiary_type' => [$required, Rule::in(CommissionRule::BENEFICIARIES)],
            'beneficiary_id' => ['nullable', 'string'],
            'basis' => [$required, Rule::in(CommissionRule::BASES)],
            'rate_bp' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'fixed_minor' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'public_id')],
            'service_type' => ['nullable', Rule::in(CommissionRuleService::SERVICE_TYPES)],
            'allow_cross_branch' => ['nullable', 'boolean'],
            'effective_from' => [$required, 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);
    }
}
