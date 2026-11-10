<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Accounting\Models\AccountingScheduleEvent;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Treasury\Models\BankReconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Read-only period-end checklist (Prompt 24).
 *
 * Every check is advisory to the UI and to whoever closes the period; closing
 * itself stays in FiscalCloseService. Nothing here posts or mutates data.
 */
class PeriodEndChecklistService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @return array{period: array<string, mixed>, can_close: bool, as_of: string, checks: array<int, array<string, mixed>>}
     */
    public function forPeriod(FiscalPeriod $period, ?Branch $branch): array
    {
        $start = $period->starts_on->toDateString();
        $end = $period->ends_on->toDateString();
        $branchId = $branch?->id;

        $drafts = JournalEntry::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'draft')
            ->whereDate('entry_date', '>=', $start)
            ->whereDate('entry_date', '<=', $end)
            ->count();

        $documents = AccountingDocument::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereIn('status', [
                AccountingDocument::STATUS_DRAFT,
                AccountingDocument::STATUS_SUBMITTED,
                AccountingDocument::STATUS_APPROVED,
            ])
            ->whereDate('fx_rate_date', '>=', $start)
            ->whereDate('fx_rate_date', '<=', $end)
            ->count();

        $expenses = Expense::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'submitted')
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->count();

        $reconciliations = BankReconciliation::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', BankReconciliation::STATUS_OPEN)
            ->whereDate('period_from', '<=', $end)
            ->whereDate('period_to', '>=', $start)
            ->count();

        $draftSchedules = AccountingSchedule::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', AccountingSchedule::STATUS_DRAFT)
            ->count();

        $dueEvents = AccountingScheduleEvent::query()
            ->when($branchId, fn ($query) => $query->whereHas(
                'schedule',
                fn ($inner) => $inner->where('branch_id', $branchId),
            ))
            ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
            ->whereDate('due_date', '<=', $end)
            ->count();

        $failedEvents = AccountingScheduleEvent::query()
            ->when($branchId, fn ($query) => $query->whereHas(
                'schedule',
                fn ($inner) => $inner->where('branch_id', $branchId),
            ))
            ->where('status', AccountingScheduleEvent::STATUS_FAILED)
            ->whereDate('due_date', '<=', $end)
            ->get(['public_id', 'due_date', 'failure_reason']);

        $negativeCash = $this->negativeCashBalances($branchId, $end);
        [$abnormalAr, $abnormalAp, $arBalance, $apBalance] = $this->abnormalPartyBalances($branchId, $end);

        $totals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $end)
            ->when($branchId, fn ($query) => $query->where('jl.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as debit, COALESCE(SUM(jl.base_credit_minor),0) as credit')
            ->first();

        $balanced = (int) $totals->debit === (int) $totals->credit;

        $checks = [
            $this->check(
                'draft_entries',
                'لا توجد قيود يومية مسودة',
                'No draft journal entries',
                $drafts === 0,
                $drafts > 0 ? "{$drafts} draft journal entries must be posted or discarded." : null,
                $drafts,
            ),
            $this->check(
                'unposted_documents',
                'لا توجد سندات غير مرحّلة',
                'No unposted accounting documents',
                $documents === 0,
                $documents > 0 ? "{$documents} vouchers are still draft, submitted or approved." : null,
                $documents,
            ),
            $this->check(
                'submitted_expenses',
                'لا توجد مصروفات بانتظار الاعتماد',
                'No expenses awaiting approval',
                $expenses === 0,
                $expenses > 0 ? "{$expenses} submitted expenses await approval." : null,
                $expenses,
            ),
            $this->check(
                'unresolved_reconciliations',
                'لا توجد مطابقات بنكية مفتوحة',
                'No open bank reconciliations',
                $reconciliations === 0,
                $reconciliations > 0 ? "{$reconciliations} bank reconciliations for this period are not approved." : null,
                $reconciliations,
            ),
            $this->check(
                'draft_schedules',
                'لا توجد جداول استحقاق مسودة',
                'No draft accrual/prepayment schedules',
                $draftSchedules === 0,
                $draftSchedules > 0 ? "{$draftSchedules} schedules are still in draft and have not been approved." : null,
                $draftSchedules,
            ),
            $this->check(
                'schedule_events_due',
                'تم ترحيل استحقاقات الفترة',
                'All schedule events due in the period are posted',
                $dueEvents === 0,
                $dueEvents > 0 ? "{$dueEvents} schedule events due by period end are still pending or failed." : null,
                $dueEvents,
            ),
            $this->check(
                'schedule_exceptions',
                'لا توجد استثناءات في جدولة الاستحقاقات',
                'No failed schedule events',
                $failedEvents->isEmpty(),
                $failedEvents->isEmpty() ? null : $failedEvents->count().' scheduled events are in the exception queue.',
                $failedEvents->count(),
                $failedEvents->map(fn ($event) => [
                    'id' => $event->public_id,
                    'due_date' => $event->due_date?->toDateString(),
                    'failure_reason' => $event->failure_reason,
                ])->values()->all(),
            ),
            $this->check(
                'negative_cash',
                'لا توجد أرصدة نقدية أو بنكية سالبة',
                'No negative cash or bank balances',
                $negativeCash === [],
                $negativeCash === [] ? null : 'One or more cash/bank accounts have a negative balance at period end.',
                count($negativeCash),
                $negativeCash,
            ),
            $this->check(
                'abnormal_ar',
                'رصيد العملاء طبيعي (مدين)',
                'Accounts receivable has a normal debit balance',
                ! $abnormalAr,
                $abnormalAr ? 'Accounts receivable is in credit at period end.' : null,
                $abnormalAr ? 1 : 0,
                ['balance_minor' => $arBalance],
            ),
            $this->check(
                'abnormal_ap',
                'رصيد الموردين طبيعي (دائن)',
                'Accounts payable has a normal credit balance',
                ! $abnormalAp,
                $abnormalAp ? 'Accounts payable is in debit at period end.' : null,
                $abnormalAp ? 1 : 0,
                ['balance_minor' => $apBalance],
            ),
            $this->check(
                'trial_balance',
                'ميزان المراجعة متوازن',
                'Trial balance is balanced',
                $balanced,
                $balanced ? null : 'Posted debits and credits differ at period end.',
                0,
                ['debit_minor' => (int) $totals->debit, 'credit_minor' => (int) $totals->credit],
            ),
        ];

        return [
            'period' => [
                'id' => (string) $period->id,
                'name' => $period->name,
                'starts_on' => $start,
                'ends_on' => $end,
                'status' => $period->status,
            ],
            'can_close' => collect($checks)->every(fn (array $check) => $check['passed'] || ! $check['blocking']),
            'as_of' => $end,
            'checks' => $checks,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function negativeCashBalances(?int $branchId, string $end): array
    {
        return DB::table('cash_accounts as ca')
            ->join('journal_lines as jl', 'jl.account_id', '=', 'ca.account_id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $end)
            ->where('ca.is_active', true)
            ->when($branchId, fn ($query) => $query->where('jl.branch_id', $branchId))
            ->groupBy('ca.public_id', 'ca.name_en', 'ca.name_ar', 'ca.currency_code')
            ->selectRaw('ca.public_id as id, ca.name_en, ca.name_ar, ca.currency_code, COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) as balance_minor')
            ->get()
            ->filter(fn ($row) => (int) $row->balance_minor < 0)
            ->map(fn ($row) => [
                'id' => $row->id,
                'name_en' => $row->name_en,
                'name_ar' => $row->name_ar,
                'currency' => $row->currency_code,
                'balance_minor' => (int) $row->balance_minor,
            ])
            ->values()
            ->all();
    }

    /** @return array{0: bool, 1: bool, 2: int, 3: int} */
    private function abnormalPartyBalances(?int $branchId, string $end): array
    {
        $arCode = (string) $this->settings->get('accounting', 'ar_account_code', '1100');
        $apCode = (string) $this->settings->get('accounting', 'ap_account_code', '2100');

        $family = function (string $code) use ($end, $branchId): int {
            $root = Account::query()->where('code', $code)->first();
            if (! $root) {
                return 0;
            }
            $ids = [$root->id];
            $frontier = [$root->id];
            while ($frontier !== []) {
                $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
                $ids = array_merge($ids, $frontier);
            }
            $row = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')
                ->whereDate('je.entry_date', '<=', $end)
                ->whereIn('jl.account_id', $ids)
                ->when($branchId, fn ($query) => $query->where('jl.branch_id', $branchId))
                ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) - COALESCE(SUM(jl.base_credit_minor),0) as balance')
                ->first();

            return (int) $row->balance;
        };

        $arBalance = $family($arCode);
        $apBalance = $family($apCode);

        return [$arBalance < 0, $apBalance > 0, $arBalance, $apBalance];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function check(
        string $key,
        string $labelAr,
        string $labelEn,
        bool $passed,
        ?string $message = null,
        int $count = 0,
        array $details = [],
        bool $blocking = true,
    ): array {
        return [
            'key' => $key,
            'label_ar' => $labelAr,
            'label_en' => $labelEn,
            'passed' => $passed,
            'blocking' => $blocking,
            'count' => $count,
            'message' => $passed ? null : $message,
            'details' => $details,
        ];
    }
}
