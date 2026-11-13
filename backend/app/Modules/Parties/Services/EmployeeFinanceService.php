<?php

namespace App\Modules\Parties\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Parties\Models\EmployeeCustody;
use App\Modules\Parties\Models\EmployeeTransaction;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\CodeGenerator;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Employee finance subsystem (Prompt 21).
 *
 * Employee master plus advance and custody lifecycles. Every movement posts
 * through PostingService to a genuine employee subaccount provisioned via
 * PartyAccount, and is mirrored in the employee subledger with a running
 * balance so each employee's statement reconciles to the GL control account.
 *
 * Employees do not require a linked login user.
 */
class EmployeeFinanceService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly FxService $fx,
    ) {}

    /** @return array<string, string> Employee categories */
    public static function categories(): array
    {
        return Employee::$categories;
    }

    /** @return array<string, string> Advance statuses */
    public static function advanceStatuses(): array
    {
        return EmployeeAdvance::$statuses;
    }

    /** @return array<string, string> Custody statuses */
    public static function custodyStatuses(): array
    {
        return EmployeeCustody::$statuses;
    }

    /**
     * Create an employee master record. User linkage is optional. The branch
     * must be within the caller's assignment (ADR-0005, work order 01).
     *
     * @param  array<string, mixed>  $data
     */
    public function createEmployee(array $data, Branch $branch, ?int $actorId): Employee
    {
        $user = request()->user();
        if ($user) {
            $this->branchScope->assertBranch($user, $branch);
        }

        // A blank internal_code is generated (EMP-0001, ...); a supplied one is kept (upper-cased).
        $supplied = strtoupper(trim((string) ($data['internal_code'] ?? '')));

        return CodeGenerator::create($supplied, 'employees', 'internal_code', 'EMP', 4, function (string $internalCode) use ($data, $branch, $actorId) {
            $employee = Employee::query()->create([
                'internal_code' => $internalCode,
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'category' => $data['category'] ?? Employee::CATEGORY_STAFF,
                'branch_id' => $branch->id,
                'user_id' => $data['user_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'is_active' => true,
            ]);

            $this->audit->log('employee.created', $employee, null, [
                'internal_code' => $internalCode,
                'category' => $employee->category,
            ], $actorId);

            return $employee->load('branch');
        });
    }

    public function updateEmployee(Employee $employee, array $data, ?int $actorId): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (! $employee->is_active && $data['is_active'] ?? false) {
                // reactivation allowed; deactivation is separate.
            }

            $before = $employee->only(['name_ar', 'name_en', 'category', 'phone', 'email', 'is_active', 'user_id']);

            $employee->update(array_filter([
                'name_ar' => $data['name_ar'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'category' => $data['category'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
            ]));

            $this->audit->log('employee.updated', $employee, $before, $data, $actorId);

            return $employee->refresh()->load('branch');
        });
    }

    public function deactivateEmployee(Employee $employee, ?int $actorId): Employee
    {
        return DB::transaction(function () use ($employee, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (! $employee->is_active) {
                throw new DomainException('Employee is already inactive.');
            }

            $outstanding = $employee->outstandingBalance();
            if ($outstanding !== 0) {
                throw new DomainException(
                    "Cannot deactivate employee with an outstanding balance of {$outstanding} minor units. Settle advances and return custody first."
                );
            }

            $employee->update(['is_active' => false]);

            $this->audit->log('employee.deactivated', $employee, ['is_active' => true], ['is_active' => false], $actorId);

            return $employee->refresh();
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function employeeStatement(int $employeeId, string $from, string $to): array
    {
        $employee = Employee::query()->findOrFail($employeeId);

        $transactions = EmployeeTransaction::query()
            ->where('employee_id', $employee->id)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->with('journalEntry')
            ->get();

        $opening = EmployeeTransaction::query()
            ->where('employee_id', $employee->id)
            ->whereDate('transaction_date', '<', $from)
            ->sum('amount_minor');

        $closing = (int) $opening + (int) $transactions->sum('amount_minor');

        return [
            'employee' => $employee->toApiArray(true),
            'from' => $from,
            'to' => $to,
            'opening_balance_minor' => (int) $opening,
            'closing_balance_minor' => $closing,
            'transactions' => $transactions->map(fn (EmployeeTransaction $t) => $t->toApiArray())->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Advances */
    /* ------------------------------------------------------------------ */

    /**
     * Request an advance. Creates a requested advance + subledger line; no
     * journal is posted until disbursement.
     *
     * @param  array<string, mixed>  $data
     */
    public function requestAdvance(array $data, Branch $branch, ?int $actorId): EmployeeAdvance
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $employee = Employee::query()->where('public_id', $data['employee_id'])->where('branch_id', $branch->id)->first();
            if (! $employee || ! $employee->is_active) {
                throw new DomainException('Active employee not found in this branch.');
            }

            $amount = (int) $data['amount_minor'];
            if ($amount <= 0) {
                throw new DomainException('Advance amount must be greater than zero.');
            }

            $advance = EmployeeAdvance::query()->create([
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'outstanding_minor' => $amount,
                'status' => EmployeeAdvance::STATUS_REQUESTED,
                'request_date' => $data['request_date'] ?? now()->toDateString(),
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->audit->log('employee_advance.requested', $advance, null, [
                'employee' => $employee->internal_code,
                'amount_minor' => $amount,
            ], $actorId);

            return $advance->load('employee', 'branch');
        });
    }

    public function approveAdvance(EmployeeAdvance $advance, ?int $actorId): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $actorId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->status !== EmployeeAdvance::STATUS_REQUESTED) {
                throw new DomainException('Only requested advances can be approved.');
            }

            // DEC-017 (work order 02): maker-checker. The person who requested
            // the advance cannot approve it while employees.segregation is on.
            if ($this->settings->bool('employees', 'segregation', true)
                && $actorId !== null
                && $actorId === $advance->created_by) {
                throw new DomainException('The person who requested an employee advance cannot approve it.');
            }

            $this->assertBranch($advance->branch_id, $actorId);

            $advance->update(['status' => EmployeeAdvance::STATUS_APPROVED, 'approved_by' => $actorId, 'approved_at' => now()]);

            $this->audit->log('employee_advance.approved', $advance, ['status' => EmployeeAdvance::STATUS_REQUESTED], [
                'status' => EmployeeAdvance::STATUS_APPROVED,
            ], $actorId);

            return $advance->refresh()->load('employee', 'branch');
        });
    }

    public function cancelAdvance(EmployeeAdvance $advance, ?int $actorId): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $actorId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if (! in_array($advance->status, [EmployeeAdvance::STATUS_REQUESTED, EmployeeAdvance::STATUS_APPROVED], true)) {
                throw new DomainException('Only requested or approved advances can be cancelled.');
            }

            $advance->update(['status' => EmployeeAdvance::STATUS_CANCELLED]);

            $this->audit->log('employee_advance.cancelled', $advance, ['status' => $advance->getOriginal('status')], [
                'status' => EmployeeAdvance::STATUS_CANCELLED,
            ], $actorId);

            return $advance->refresh()->load('employee', 'branch');
        });
    }

    /**
     * Disburse an approved advance: DR employee advance (asset) / CR cash.
     */
    public function disburseAdvance(EmployeeAdvance $advance, Branch $branch, array $data, ?int $actorId): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $branch, $data, $actorId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->status !== EmployeeAdvance::STATUS_APPROVED) {
                throw new DomainException('Only approved advances can be disbursed.');
            }

            $this->assertBranch($advance->branch_id, $actorId);

            $cashAccount = $this->cashAccount($data['cash_account_id'] ?? null);
            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $date = $data['disbursed_date'] ?? now()->toDateString();
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date)));

            $amount = $advance->amount_minor;

            $advanceAccount = $this->posting->account((string) $this->settings->get('accounting', 'employee_advance_account_code', '1151'));

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $currency,
                lines: [
                    PostingLine::debit($advanceAccount, $amount, 'employee', $advance->employee_id, 'Employee advance disbursed'),
                    PostingLine::credit($cashAccount, $amount, null, null, 'Cash disbursed for advance'),
                ],
                memo: 'Employee advance disbursed '.$advance->public_id,
                sourceType: 'employee_advance',
                sourceId: $advance->id,
                event: 'disbursed',
                fxRate: $fxRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $advance->update([
                'status' => EmployeeAdvance::STATUS_DISBURSED,
                'disbursed_date' => $date,
                'disbursement_journal_entry_id' => $entry->id,
                'cash_account_id' => $cashAccount->id,
            ]);

            $this->recordTransaction($advance->employee, $branch, EmployeeTransaction::TYPE_ADVANCE_DISBURSED, $amount, $entry, $advance, $actorId, 'Advance disbursed');

            $this->audit->log('employee_advance.disbursed', $advance, ['status' => EmployeeAdvance::STATUS_APPROVED], [
                'status' => EmployeeAdvance::STATUS_DISBURSED,
                'journal' => $entry->number,
            ], $actorId);

            return $advance->refresh()->load('employee', 'branch', 'cashAccount');
        });
    }

    /**
     * Settle an advance (fully or partially). Settlements are expense lines
     * (receipts) reducing the advance, plus an optional cash return.
     *
     * @param  array<string, mixed>  $data
     */
    public function settleAdvance(EmployeeAdvance $advance, array $data, ?int $actorId): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $data, $actorId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if (! in_array($advance->status, [EmployeeAdvance::STATUS_DISBURSED], true)) {
                throw new DomainException('Only disbursed advances can be settled.');
            }

            $this->assertBranch($advance->branch_id, $actorId);

            $branch = $advance->branch;
            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $date = $data['settled_date'] ?? now()->toDateString();
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date)));

            $advanceAccount = $this->posting->account((string) $this->settings->get('accounting', 'employee_advance_account_code', '1151'));

            $settlementAmount = (int) ($data['settlement_amount_minor'] ?? 0);
            $cashReturn = (int) ($data['cash_return_minor'] ?? 0);

            if ($settlementAmount <= 0 && $cashReturn <= 0) {
                throw new DomainException('Specify a settlement amount or a cash return.');
            }

            if ($settlementAmount + $cashReturn > $advance->outstanding_minor) {
                throw new DomainException('Settlement plus cash return exceeds the outstanding advance.');
            }

            $lines = [];
            $expenseTotal = 0;

            // Expense receipts reduce the advance: CR advance, DR expense.
            $expenseLines = $data['expense_lines'] ?? [];
            foreach ($expenseLines as $index => $line) {
                $account = $this->resolveAccount($line['account_id']);
                $lineAmount = (int) $line['amount_minor'];
                if ($lineAmount <= 0) {
                    throw new DomainException("Expense line {$index} amount must be greater than zero.");
                }
                $lines[] = PostingLine::credit($advanceAccount, $lineAmount, 'employee', $advance->employee_id, 'Advance settled: '.$line['description']);
                $lines[] = PostingLine::debit($account, $lineAmount, null, null, $line['description']);
                $expenseTotal += $lineAmount;
            }

            if (isset($data['settlement_amount_minor']) && (int) $data['settlement_amount_minor'] !== $expenseTotal) {
                throw new DomainException('Expense lines do not add up to the declared settlement amount.');
            }

            // Cash return: CR advance, DR cash.
            $cashReturn = (int) ($data['cash_return_minor'] ?? 0);
            if ($cashReturn > 0) {
                $cashAccount = $this->cashAccount($data['cash_account_id'] ?? null);
                $lines[] = PostingLine::credit($advanceAccount, $cashReturn, 'employee', $advance->employee_id, 'Cash returned against advance');
                $lines[] = PostingLine::debit($cashAccount, $cashReturn, null, null, 'Cash returned');
            }

            $settledTotal = $expenseTotal + $cashReturn;

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $currency,
                lines: $lines,
                memo: 'Employee advance settled '.$advance->public_id,
                sourceType: 'employee_advance',
                sourceId: $advance->id,
                event: 'settled:'.$advance->outstanding_minor,
                fxRate: $fxRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $newOutstanding = $advance->outstanding_minor - $settledTotal;
            $newStatus = $newOutstanding <= 0 ? EmployeeAdvance::STATUS_SETTLED : EmployeeAdvance::STATUS_DISBURSED;

            $advance->update([
                'status' => $newStatus,
                'outstanding_minor' => $newOutstanding,
                'settled_date' => $newStatus === EmployeeAdvance::STATUS_SETTLED ? $date : null,
            ]);

            $this->recordTransaction($advance->employee, $branch, EmployeeTransaction::TYPE_ADVANCE_SETTLED, -$settledTotal, $entry, $advance, $actorId, 'Advance settled');

            $this->audit->log('employee_advance.settled', $advance, [
                'status' => $advance->getOriginal('status'),
                'outstanding_minor' => $advance->getOriginal('outstanding_minor'),
            ], [
                'status' => $newStatus,
                'outstanding_minor' => $newOutstanding,
                'journal' => $entry->number,
            ], $actorId);

            return $advance->refresh()->load('employee', 'branch', 'cashAccount');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Custody */
    /* ------------------------------------------------------------------ */

    /**
     * Issue a custody item / petty-cash float: DR employee custody / CR cash.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueCustody(array $data, Branch $branch, ?int $actorId): EmployeeCustody
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $employee = Employee::query()->where('public_id', $data['employee_id'])->where('branch_id', $branch->id)->first();
            if (! $employee || ! $employee->is_active) {
                throw new DomainException('Active employee not found in this branch.');
            }

            $amount = (int) $data['amount_minor'];
            if ($amount <= 0) {
                throw new DomainException('Custody amount must be greater than zero.');
            }

            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $date = $data['issue_date'] ?? now()->toDateString();
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date)));

            $cashAccount = $this->cashAccount($data['cash_account_id'] ?? null);
            $custodyAccount = $this->posting->account((string) $this->settings->get('accounting', 'employee_custody_account_code', '1152'));

            $custody = EmployeeCustody::query()->create([
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'outstanding_minor' => $amount,
                'status' => EmployeeCustody::STATUS_ISSUED,
                'cash_account_id' => $cashAccount->id,
                'item_description' => $data['item_description'] ?? null,
                'issue_date' => $date,
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
            ]);

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $currency,
                lines: [
                    PostingLine::debit($custodyAccount, $amount, 'employee', $employee->id, 'Custody issued'),
                    PostingLine::credit($cashAccount, $amount, null, null, 'Cash issued as custody'),
                ],
                memo: 'Employee custody issued '.$employee->internal_code,
                sourceType: 'employee_custody',
                sourceId: $custody->id,
                event: 'issued',
                fxRate: $fxRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $custody->update(['issuance_journal_entry_id' => $entry->id]);

            $this->recordTransaction($employee, $branch, EmployeeTransaction::TYPE_CUSTODY_ISSUED, $amount, $entry, $custody, $actorId, 'Custody issued');

            $this->audit->log('employee_custody.issued', $custody, null, [
                'employee' => $employee->internal_code,
                'amount_minor' => $amount,
            ], $actorId);

            return $custody->load('employee', 'branch', 'cashAccount');
        });
    }

    public function clearCustody(EmployeeCustody $custody, array $data, ?int $actorId): EmployeeCustody
    {
        return DB::transaction(function () use ($custody, $data, $actorId) {
            $custody = EmployeeCustody::query()->lockForUpdate()->findOrFail($custody->id);

            if (! in_array($custody->status, [EmployeeCustody::STATUS_ISSUED, EmployeeCustody::STATUS_CLEARED], true)) {
                throw new DomainException('Only issued or partially-cleared custody can be cleared further.');
            }

            $this->assertBranch($custody->branch_id, $actorId);

            $branch = $custody->branch;
            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $date = $data['cleared_date'] ?? now()->toDateString();
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date)));

            $custodyAccount = $this->posting->account((string) $this->settings->get('accounting', 'employee_custody_account_code', '1152'));

            $clearedTotal = 0;
            $lines = [];

            $expenseLines = $data['expense_lines'] ?? [];
            foreach ($expenseLines as $index => $line) {
                $account = $this->resolveAccount($line['account_id']);
                $lineAmount = (int) $line['amount_minor'];
                if ($lineAmount <= 0) {
                    throw new DomainException("Expense line {$index} amount must be greater than zero.");
                }
                $lines[] = PostingLine::debit($account, $lineAmount, null, null, 'Custody cleared: '.$line['description']);
                $lines[] = PostingLine::credit($custodyAccount, $lineAmount, 'employee', $custody->employee_id, 'Custody cleared');
                $clearedTotal += $lineAmount;
            }

            if (isset($data['cleared_amount_minor']) && (int) $data['cleared_amount_minor'] !== $clearedTotal) {
                throw new DomainException('Expense lines do not add up to the declared cleared amount.');
            }

            if ($clearedTotal <= 0) {
                throw new DomainException('Specify cleared expense lines.');
            }

            if ($clearedTotal > $custody->outstanding_minor) {
                throw new DomainException('Cleared amount exceeds outstanding custody.');
            }

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $currency,
                lines: $lines,
                memo: 'Employee custody cleared '.$custody->public_id,
                sourceType: 'employee_custody',
                sourceId: $custody->id,
                event: 'cleared:'.$custody->outstanding_minor,
                fxRate: $fxRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $newOutstanding = $custody->outstanding_minor - $clearedTotal;
            $newStatus = $newOutstanding <= 0 ? EmployeeCustody::STATUS_RETURNED : EmployeeCustody::STATUS_CLEARED;

            $custody->update([
                'status' => $newStatus,
                'outstanding_minor' => $newOutstanding,
                'returned_date' => $newStatus === EmployeeCustody::STATUS_RETURNED ? $date : null,
            ]);

            $this->recordTransaction($custody->employee, $branch, EmployeeTransaction::TYPE_CUSTODY_CLEARED, -$clearedTotal, $entry, $custody, $actorId, 'Custody cleared');

            $this->audit->log('employee_custody.cleared', $custody, [
                'status' => $custody->getOriginal('status'),
                'outstanding_minor' => $custody->getOriginal('outstanding_minor'),
            ], [
                'status' => $newStatus,
                'outstanding_minor' => $newOutstanding,
                'journal' => $entry->number,
            ], $actorId);

            return $custody->refresh()->load('employee', 'branch', 'cashAccount');
        });
    }

    public function returnCustody(EmployeeCustody $custody, array $data, ?int $actorId): EmployeeCustody
    {
        return DB::transaction(function () use ($custody, $data, $actorId) {
            $custody = EmployeeCustody::query()->lockForUpdate()->findOrFail($custody->id);

            if (! in_array($custody->status, [EmployeeCustody::STATUS_ISSUED, EmployeeCustody::STATUS_CLEARED], true)) {
                throw new DomainException('Only issued or cleared custody can be returned (cash handed back).');
            }

            $this->assertBranch($custody->branch_id, $actorId);

            $branch = $custody->branch;
            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $date = $data['returned_date'] ?? now()->toDateString();
            $fxRate = $currency === $this->settings->baseCurrency()
                ? '1'
                : ((string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $date)));

            $amount = $custody->outstanding_minor;
            if ($amount <= 0) {
                throw new DomainException('Nothing to return.');
            }

            $cashAccount = $this->cashAccount($data['cash_account_id'] ?? null);
            $custodyAccount = $this->posting->account((string) $this->settings->get('accounting', 'employee_custody_account_code', '1152'));

            // Return the remaining custody value: CR custody, DR cash.
            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $currency,
                lines: [
                    PostingLine::debit($cashAccount, $amount, null, null, 'Custody cash returned'),
                    PostingLine::credit($custodyAccount, $amount, 'employee', $custody->employee_id, 'Custody returned'),
                ],
                memo: 'Employee custody returned '.$custody->public_id,
                sourceType: 'employee_custody',
                sourceId: $custody->id,
                event: 'returned',
                fxRate: $fxRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $custody->update(['status' => EmployeeCustody::STATUS_CLOSED, 'outstanding_minor' => 0]);

            $this->recordTransaction($custody->employee, $branch, EmployeeTransaction::TYPE_CUSTODY_RETURNED, -$amount, $entry, $custody, $actorId, 'Custody returned');

            $this->audit->log('employee_custody.returned', $custody, ['status' => EmployeeCustody::STATUS_RETURNED], [
                'status' => EmployeeCustody::STATUS_CLOSED,
                'journal' => $entry->number,
            ], $actorId);

            return $custody->refresh()->load('employee', 'branch', 'cashAccount');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function cashAccount(?string $publicId): Account
    {
        if ($publicId) {
            $cashAccount = CashAccount::query()->where('public_id', $publicId)->first();
            if ($cashAccount && $cashAccount->account && $cashAccount->account->is_active && $cashAccount->account->is_postable) {
                return $cashAccount->account;
            }
        }

        throw new DomainException('A valid active cash or bank account is required.');
    }

    /**
     * Resolve an account reference that may be a public_id or a code.
     */
    private function resolveAccount(string $reference): Account
    {
        return Account::query()->where('public_id', $reference)->first()
            ?? Account::query()->where('code', $reference)->first()
            ?? throw new DomainException("Account {$reference} is not configured.");
    }

    /**
     * Append a subledger transaction with a running balance.
     *
     * @param  array<string, mixed>|null  $extra
     */
    private function recordTransaction(Employee $employee, Branch $branch, string $type, int $amount, $journalEntry, $source, ?int $actorId, string $memo): void
    {
        $previous = EmployeeTransaction::query()
            ->where('employee_id', $employee->id)
            ->max('balance_minor') ?? 0;

        $sourceType = $source instanceof EmployeeAdvance ? 'advance' : 'custody';

        EmployeeTransaction::query()->create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'type' => $type,
            'reference' => null,
            'journal_entry_id' => $journalEntry ? $journalEntry->id : null,
            'source_id' => $source ? $source->id : null,
            'source_type' => $sourceType,
            'transaction_date' => $journalEntry ? $journalEntry->entry_date->toDateString() : now()->toDateString(),
            'currency_code' => $journalEntry ? $journalEntry->currency_code : $this->settings->baseCurrency(),
            'fx_rate' => $journalEntry ? $journalEntry->fx_rate : '1',
            'fx_rate_date' => $journalEntry ? $journalEntry->fx_rate_date : now()->toDateString(),
            'amount_minor' => $amount,
            'balance_minor' => (int) $previous + $amount,
            'memo' => $memo,
            'created_by' => $actorId,
        ]);
    }

    private function assertBranch(int $branchId, ?int $actorId): void
    {
        $user = $actorId ? User::query()->find($actorId) : null;
        if ($user) {
            $this->branchScope->assertBranch($user, Branch::query()->find($branchId));
        }
    }
}
