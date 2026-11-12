<?php

namespace App\Http\Controllers\Api;

use App\Modules\Commissions\Models\Commission;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $commissions = Commission::query()->with('booking', 'branch', 'rule', 'journalEntry', 'reversalJournalEntry')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('beneficiary_type'), fn ($q, $type) => $q->where('beneficiary_type', $type))
            ->when($request->query('booking_id'), fn ($q, $id) => $q->whereHas('booking', fn ($b) => $b->where('public_id', $id)))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('accrued_on', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('accrued_on', '<=', $to))
            ->orderByDesc('accrued_on')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $commissions->getCollection()->transform(fn (Commission $c) => $c->toApiArray());

        return $this->paginated($commissions);
    }

    public function show(Commission $commission): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);

        $payload = $commission->load('booking', 'branch', 'rule', 'journalEntry', 'reversalJournalEntry', 'payments.cashAccount', 'payments.journalEntry')->toApiArray();
        $payload['payments'] = $commission->payments->map(fn ($p) => $p->toApiArray())->all();

        return $this->ok($payload);
    }

    public function override(Request $request, Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->ok($service->override($commission, (int) $data['amount_minor'], $data['reason'], $this->actorId())->toApiArray());
    }

    public function approve(Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);

        return $this->ok($service->approve($commission, $this->actorId())->toApiArray());
    }

    public function reject(Request $request, Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($service->reject($commission, $data['reason'], $this->actorId())->toApiArray());
    }

    public function post(Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);

        return $this->ok($service->post($commission, $this->actorId())->toApiArray());
    }

    public function pay(Request $request, Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);
        $data = $request->validate([
            'cash_account_id' => ['required', 'string', Rule::exists('cash_accounts', 'public_id')],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->created($service->pay($commission, $data, $this->actorId())->toApiArray());
    }

    public function reverse(Request $request, Commission $commission, CommissionService $service): JsonResponse
    {
        $this->authorizeBranchId($commission->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($service->reverse($commission, $data['reason'], $this->actorId())->toApiArray());
    }

    /** Beneficiaries with commission activity and their totals. */
    public function beneficiaries(Request $request, CommissionService $service, BranchScope $scope): JsonResponse
    {
        $data = $request->validate(['beneficiary_type' => ['nullable', Rule::in(CommissionRule::BENEFICIARIES)]]);

        return $this->ok($service->beneficiaries($scope->allowedBranchIds($request->user()), $data['beneficiary_type'] ?? null));
    }

    /** Commission statement of one beneficiary (employee, agent or branch). */
    public function statement(Request $request, CommissionService $service, BranchScope $scope): JsonResponse
    {
        $data = $request->validate([
            'beneficiary_type' => ['required', Rule::in(CommissionRule::BENEFICIARIES)],
            'beneficiary_id' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $id = match ($data['beneficiary_type']) {
            'employee' => Employee::query()->where('public_id', $data['beneficiary_id'])->value('id'),
            'agent' => Supplier::query()->where('public_id', $data['beneficiary_id'])->where('category', 'agent')->value('id'),
            'branch' => Branch::query()->where('public_id', $data['beneficiary_id'])->value('id'),
        };
        abort_if($id === null, 404, 'Beneficiary not found.');

        $from = $data['from'] ?? now()->startOfYear()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        return $this->ok($service->statement($data['beneficiary_type'], (int) $id, $from, $to, $scope->allowedBranchIds($request->user())));
    }
}
