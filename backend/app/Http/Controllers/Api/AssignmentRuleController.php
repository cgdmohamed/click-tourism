<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\CRM\Models\AssignmentRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentRuleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $rules = AssignmentRule::query()->with('targetUser')
            ->when($branch, fn ($query) => $query->where(function ($query) use ($branch) {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            }))
            ->when($request->boolean('include_inactive') !== true, fn ($query) => $query->where('is_active', true))
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (AssignmentRule $rule) => $rule->toApiArray());

        return $this->ok($rules);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['nullable', 'integer', 'min:0'],
            'branch_id' => ['nullable', 'string'],
            'source_id' => ['nullable', 'string'],
            'service_category' => ['nullable', 'string', 'max:40'],
            'strategy' => ['required', Rule::in(['least_loaded', 'round_robin', 'manual'])],
            'target_user_id' => ['nullable', 'string'],
            'max_leads_per_user' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch = null;
        if (! empty($data['branch_id'])) {
            $branch = Branch::query()->where('public_id', $data['branch_id'])->firstOrFail();
            $this->authorizeBranchId($branch->id);
        }

        $targetUser = null;
        if (! empty($data['target_user_id'])) {
            $targetUser = User::query()->where('public_id', $data['target_user_id'])->firstOrFail();
        }

        $rule = AssignmentRule::query()->create([
            'priority' => $data['priority'] ?? 0,
            'branch_id' => $branch?->id,
            'source_id' => $data['source_id'] ?? null,
            'service_category' => $data['service_category'] ?? null,
            'strategy' => $data['strategy'],
            'target_user_id' => $targetUser?->id,
            'max_leads_per_user' => $data['max_leads_per_user'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $audit->log('assignment_rule.created', $rule, null, $rule->toApiArray(), $this->actorId());

        return $this->created($rule->toApiArray());
    }

    public function update(Request $request, AssignmentRule $rule, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($rule->branch_id);

        $data = $request->validate([
            'priority' => ['sometimes', 'integer', 'min:0'],
            'source_id' => ['sometimes', 'nullable', 'string'],
            'service_category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'strategy' => ['sometimes', Rule::in(['least_loaded', 'round_robin', 'manual'])],
            'target_user_id' => ['sometimes', 'nullable', 'string'],
            'max_leads_per_user' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $rule->only(array_keys($data));

        if (! empty($data['target_user_id'])) {
            $targetUser = User::query()->where('public_id', $data['target_user_id'])->firstOrFail();
            $data['target_user_id'] = $targetUser->id;
        } elseif (isset($data['target_user_id'])) {
            $data['target_user_id'] = null;
        }

        $rule->update($data);
        $audit->log('assignment_rule.updated', $rule, $before, $rule->toApiArray(), $this->actorId());

        return $this->ok($rule->refresh()->toApiArray());
    }

    public function destroy(AssignmentRule $rule, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($rule->branch_id);

        $audit->log('assignment_rule.deleted', $rule, $rule->toApiArray(), null, $this->actorId());
        $rule->delete();

        return $this->ok(['message' => 'Assignment rule deleted.']);
    }
}
