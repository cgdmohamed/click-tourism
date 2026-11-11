<?php

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Ledger-based AR/AP aging and control-account reconciliation (Prompt 28).
 *
 * Operational invoice open balances and unapplied credits are compared with
 * the posted control-account family. Differences are returned with explicit
 * reasons; they are never silently written off. `/reports/aging` uses the
 * same open-document definition (ReportService::openDocuments()).
 */
class AgingReconciliationService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly RoundingPolicy $rounding,
        private readonly ReportReferences $references,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(string $type, string $asOf, ?Branch $branch): array
    {
        $partyType = $type === 'ar' ? 'customer' : 'supplier';
        $currency = $this->settings->baseCurrency();
        $control = $this->controlAccount($type);
        $familyIds = $this->descendantIds($control);

        $ledgerSigned = $this->ledgerBalance($familyIds, $asOf, $branch);
        $ledgerNormalized = $type === 'ar' ? $ledgerSigned : -$ledgerSigned;

        [$openInvoices, $invoiceCount, $buckets, $byParty] = $this->openInvoices($partyType, $asOf, $branch, $currency);
        $unapplied = $this->unappliedCredits($partyType, $asOf, $branch, $currency);

        $advanceAccount = $this->advanceAccount($type);
        $advanceFamily = $advanceAccount ? $this->descendantIds($advanceAccount) : [];
        $advanceLedger = $advanceAccount
            ? ($type === 'ar'
                ? -$this->ledgerBalance($advanceFamily, $asOf, $branch)
                : $this->ledgerBalance($advanceFamily, $asOf, $branch))
            : 0;

        $difference = $ledgerNormalized - $openInvoices;
        $untagged = $this->untaggedBalance($familyIds, $partyType, $asOf, $branch, $type);

        $differences = [];
        if ($difference < 0) {
            $differences[] = [
                'code' => 'payments_not_applied_to_invoices',
                'label_en' => 'Ledger receipts/payments exceed open invoice balances',
                'label_ar' => 'المدفوعات في دفتر الأستاذ تتجاوز أرصدة الفواتير المفتوحة',
                'amount_minor' => -$difference,
            ];
        } elseif ($difference > 0) {
            $differences[] = [
                'code' => 'invoices_not_reflected_in_ledger',
                'label_en' => 'Open invoice balances exceed the control-account ledger',
                'label_ar' => 'أرصدة الفواتير المفتوحة تتجاوز رصيد حساب المراقبة',
                'amount_minor' => $difference,
            ];
        }

        if ($untagged !== 0) {
            $differences[] = [
                'code' => 'untagged_control_balance',
                'label_en' => 'Control-account lines without a party tag',
                'label_ar' => 'حركات على حساب المراقبة بدون ربط بطرف',
                'amount_minor' => $untagged,
            ];
        }

        $advanceDifference = $advanceLedger - $unapplied;

        return [
            'type' => $type,
            'party_type' => $partyType,
            'currency' => $currency,
            'as_of' => $asOf,
            'ledger' => [
                'control_account' => $control->toApiArray(),
                'signed_balance_minor' => $ledgerSigned,
                'balance_minor' => $ledgerNormalized,
            ],
            'operational' => [
                'open_invoices_minor' => $openInvoices,
                'invoice_count' => $invoiceCount,
                'unapplied_advances_minor' => $unapplied,
                'buckets' => $buckets,
            ],
            'advances' => [
                'account' => $advanceAccount?->toApiArray(),
                'ledger_minor' => $advanceLedger,
                'operational_minor' => $unapplied,
                'difference_minor' => $advanceDifference,
                'reconciled' => $advanceDifference === 0,
            ],
            'difference_minor' => $difference,
            'reconciled' => $difference === 0 && $untagged === 0,
            'differences' => $differences,
            'parties' => $byParty,
        ];
    }

    private function controlAccount(string $type): Account
    {
        $code = $type === 'ar'
            ? (string) $this->settings->get('accounting', 'ar_account_code', '1100')
            : (string) $this->settings->get('accounting', 'ap_account_code', '2100');

        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function advanceAccount(string $type): ?Account
    {
        $code = $type === 'ar'
            ? (string) $this->settings->get('accounting', 'advance_account_code', '2300')
            : (string) $this->settings->get('accounting', 'supplier_advance_account_code', '1150');

        return Account::query()->where('code', $code)->first();
    }

    /** @return int[] */
    private function descendantIds(Account $account): array
    {
        $ids = [$account->id];
        $frontier = [$account->id];

        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @param int[] $familyIds */
    private function ledgerBalance(array $familyIds, string $asOf, ?Branch $branch): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $familyIds)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    /**
     * @param  int[]  $familyIds
     */
    private function untaggedBalance(array $familyIds, string $partyType, string $asOf, ?Branch $branch, string $type): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $familyIds)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->where(function ($query) use ($partyType) {
                $query->whereNull('jl.party_type')->orWhere('jl.party_type', '!=', $partyType);
            })
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        $signed = (int) $row->debit - (int) $row->credit;

        return $type === 'ar' ? $signed : -$signed;
    }

    /**
     * @return array{0: int, 1: int, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>}
     */
    private function openInvoices(string $partyType, string $asOf, ?Branch $branch, string $currency): array
    {
        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $parties = [];
        $total = 0;

        // Same definition as ReportService::aging(): issued, outstanding > 0.
        $invoices = ReportService::openDocuments($partyType, $asOf, $branch);

        foreach ($invoices as $invoice) {
            $amount = $invoice->type === Invoice::TYPE_CREDIT_NOTE
                ? -(int) $invoice->outstanding_minor
                : (int) $invoice->outstanding_minor;

            $base = (new Money($amount, $invoice->currency_code))
                ->convertTo($currency, (string) $invoice->fx_rate, $this->rounding->mode())->amountMinor;

            $total += $base;

            $buckets[ReportService::bucketFor(ReportService::ageInDays($invoice, $asOf))] += $base;

            $key = $partyType.':'.$invoice->party_id;
            $parties[$key] ??= [
                'party_type' => $partyType,
                'party_id' => $invoice->party_id,
                'ledger_minor' => 0,
                'open_invoices_minor' => 0,
                'difference_minor' => 0,
            ];
            $parties[$key]['open_invoices_minor'] += $base;
        }

        $ledgerByParty = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->descendantIds($this->controlAccount($partyType === 'customer' ? 'ar' : 'ap')))
            ->where('jl.party_type', $partyType)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->groupBy('jl.party_id')
            ->selectRaw('jl.party_id, COALESCE(SUM(jl.base_debit_minor), 0) - COALESCE(SUM(jl.base_credit_minor), 0) as balance')
            ->get();

        foreach ($ledgerByParty as $row) {
            $key = $partyType.':'.$row->party_id;
            $parties[$key] ??= [
                'party_type' => $partyType,
                'party_id' => (int) $row->party_id,
                'open_invoices_minor' => 0,
            ];
            $parties[$key]['ledger_minor'] = $partyType === 'customer' ? (int) $row->balance : -(int) $row->balance;
        }

        $partyIds = array_values(array_filter(array_map(
            fn ($key) => (int) substr($key, strpos($key, ':') + 1),
            array_keys($parties),
        )));

        $names = $this->references->resolveParties(
            array_fill(0, count($partyIds), $partyType),
            $partyIds,
        );

        $result = [];
        foreach ($parties as $key => $party) {
            $party['ledger_minor'] = (int) ($party['ledger_minor'] ?? 0);
            $party['open_invoices_minor'] = (int) ($party['open_invoices_minor'] ?? 0);
            $party['difference_minor'] = $party['ledger_minor'] - $party['open_invoices_minor'];
            $party['name_en'] = $names[$key]['name_en'] ?? null;
            $party['name_ar'] = $names[$key]['name_ar'] ?? null;
            $party['public_id'] = $names[$key]['public_id'] ?? null;
            $result[] = $party;
        }

        usort($result, fn ($a, $b) => abs($b['difference_minor']) <=> abs($a['difference_minor']));

        $bucketResult = [];
        foreach ($buckets as $key => $amount) {
            $bucketResult[] = ['key' => $key, 'amount_minor' => $amount];
        }

        return [$total, $invoices->count(), $bucketResult, $result];
    }

    private function unappliedCredits(string $partyType, string $asOf, ?Branch $branch, string $currency): int
    {
        $total = 0;

        if ($partyType === 'customer') {
            $payments = Payment::query()
                ->whereDate('paid_at', '<=', $asOf)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($payments as $payment) {
                $total += (new Money((int) $payment->unallocated_minor, $payment->currency_code))
                    ->convertTo($currency, (string) $payment->fx_rate, $this->rounding->mode())->amountMinor;
            }
        } else {
            $payments = SupplierPayment::query()
                ->withSum('applications', 'amount_minor')
                ->whereDate('paid_at', '<=', $asOf)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->get();

            foreach ($payments as $payment) {
                $unallocated = max(0, (int) $payment->amount_minor - (int) ($payment->applications_sum_amount_minor ?? 0));
                $total += (new Money($unallocated, $payment->currency_code))
                    ->convertTo($currency, (string) $payment->fx_rate, $this->rounding->mode())->amountMinor;
            }
        }

        return $total;
    }
}
