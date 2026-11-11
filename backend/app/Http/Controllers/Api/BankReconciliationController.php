<?php

namespace App\Http\Controllers\Api;

use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Models\BankStatement;
use App\Modules\Treasury\Services\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bank reconciliation API (Prompt 23, TE-023).
 *
 * Statements are imported per bank account, reconciliation sessions link
 * statement lines to existing posted journal lines, and the only ledger
 * effect is a draft adjustment voucher that the user explicitly proposes.
 */
class BankReconciliationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $sessions = BankReconciliation::query()
            ->with('cashAccount.account', 'branch', 'statement')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('cash_account_id'), function ($query, $cashAccount) {
                $query->whereHas('cashAccount', fn ($inner) => $inner->where('public_id', $cashAccount));
            })
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('period_from'), fn ($query, $from) => $query->whereDate('period_to', '>=', $from))
            ->when($request->query('period_to'), fn ($query, $to) => $query->whereDate('period_from', '<=', $to))
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get();

        return $this->ok($sessions->map(fn (BankReconciliation $session) => $session->toApiArray(false))->values());
    }

    public function statements(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $statements = BankStatement::query()
            ->with('cashAccount', 'branch')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('cash_account_id'), function ($query, $cashAccount) {
                $query->whereHas('cashAccount', fn ($inner) => $inner->where('public_id', $cashAccount));
            })
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get();

        return $this->ok($statements->map(fn (BankStatement $statement) => $statement->toApiArray())->values());
    }

    public function import(Request $request, BankReconciliationService $service): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'cash_account_id' => ['required', 'string', Rule::exists('cash_accounts', 'public_id')],
            'delimiter' => ['nullable', 'string', 'max:1'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'opening_balance_minor' => ['nullable', 'integer'],
            'closing_balance_minor' => ['nullable', 'integer'],
            'mapping' => ['nullable', 'array'],
            'mapping.date' => ['nullable'],
            'mapping.value_date' => ['nullable'],
            'mapping.reference' => ['nullable'],
            'mapping.description' => ['nullable'],
            'mapping.party' => ['nullable'],
            'mapping.currency' => ['nullable'],
            'mapping.amount' => ['nullable'],
            'mapping.money_in' => ['nullable'],
            'mapping.money_out' => ['nullable'],
            'mapping.debit' => ['nullable'],
            'mapping.credit' => ['nullable'],
            'mapping.balance' => ['nullable'],
            'mapping.date_format' => ['nullable', 'string', 'max:20'],
        ]);

        $result = $service->import(
            $request->file('file'),
            $data,
            $this->branch(),
            $this->actorId(),
        );

        return $this->created([
            'statement' => $result['statement']->toApiArray(true),
            'imported_lines' => $result['imported_lines'],
            'skipped_duplicates' => $result['skipped_duplicates'],
        ]);
    }

    public function showStatement(BankStatement $statement): JsonResponse
    {
        $this->authorizeBranchId($statement->branch_id);

        return $this->ok($statement->load('cashAccount.account', 'branch', 'lines')->toApiArray(true));
    }

    public function store(Request $request, BankReconciliationService $service): JsonResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'string', Rule::exists('cash_accounts', 'public_id')],
            'statement_id' => ['nullable', 'string', Rule::exists('bank_statements', 'public_id')],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'opening_bank_balance_minor' => ['nullable', 'integer'],
            'closing_bank_balance_minor' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $reconciliation = $service->createSession($data, $this->branch(), $this->actorId());

        return $this->created($service->detail($reconciliation));
    }

    public function show(BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        return $this->ok($service->detail($reconciliation));
    }

    public function autoMatch(BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $result = $service->autoMatch($reconciliation, $this->actorId());

        return $this->ok([
            ...$result,
            'reconciliation' => $service->detail($reconciliation->refresh()),
        ]);
    }

    public function match(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.bank_line_id' => ['required', 'string'],
            'items.*.journal_line_id' => ['required', 'string'],
            'items.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $service->match($reconciliation, $data['items'], $this->actorId());

        return $this->ok($service->detail($reconciliation->refresh()));
    }

    public function unmatch(BankReconciliation $reconciliation, string $group, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $service->unmatch($reconciliation, $group, $this->actorId());

        return $this->ok($service->detail($reconciliation->refresh()));
    }

    public function adjustmentVoucher(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $data = $request->validate([
            'bank_line_ids' => ['nullable', 'array'],
            'bank_line_ids.*' => ['string'],
            'charges_account_code' => ['nullable', 'string', 'max:20'],
            'interest_account_code' => ['nullable', 'string', 'max:20'],
            'document_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'submit' => ['nullable', 'boolean'],
        ]);

        return $this->created($service->proposeAdjustment($reconciliation, $data, $this->actorId()));
    }

    public function reopen(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $service->reopen($reconciliation, $data['reason'], $this->actorId());

        return $this->ok($service->detail($reconciliation->refresh()));
    }

    public function approve(BankReconciliation $reconciliation, BankReconciliationService $service): JsonResponse
    {
        $this->authorizeBranchId($reconciliation->branch_id);

        $service->approve($reconciliation, $this->actorId());

        return $this->ok($service->detail($reconciliation->refresh()));
    }
}
