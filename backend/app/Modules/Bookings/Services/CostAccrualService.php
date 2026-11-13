<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Services\ReportService;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEC-002: period-end accrual of supplier costs that were incurred but not yet invoiced.
 *
 * Dr cost of services / Cr accrued supplier costs, per booking, dated the last day of the
 * period and reversed automatically on the first day of the next one. The supplier invoice is
 * posted normally when it arrives, so the reversal removes the accrual and the cost is never
 * counted twice. Idempotent per booking and period; a booking whose cost the books already
 * carry (booking-cost posting or supplier invoices) is never accrued again.
 */
class CostAccrualService
{
    public const EVENT = 'cost_accrual';

    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly ReportService $reports,
        private readonly AuditService $audit,
    ) {}

    /**
     * Bookings of the branch whose service has started by $endDate and whose estimated cost
     * exceeds what the ledger carries at that date.
     *
     * @return list<array{booking: Booking, estimated_minor: int, recognized_minor: int, accrual_minor: int, remaining_by_supplier: array<int, int>, already_accrued: bool}>
     */
    public function preview(string $endDate, Branch $branch, ?FiscalPeriod $period = null): array
    {
        $base = $this->settings->baseCurrency();
        $costIds = $this->reports->costOfServicesAccountIds();
        $costCode = $this->reports->costAccountCode();
        $rows = [];

        $bookings = Booking::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed', 'closed'])
            ->whereDate('start_date', '<=', $endDate)
            ->where('total_cost_minor', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            $estimated = strtoupper((string) $booking->currency_code) === $base
                ? (int) $booking->total_cost_minor
                : (new Money((int) $booking->total_cost_minor, (string) $booking->currency_code))->convertTo($base, (string) ($booking->fx_rate ?: '1'))->amountMinor;

            $carried = $this->carriedCostBySupplier($booking, $endDate, $period?->starts_on?->toDateString(), $costIds, $costCode);
            $recognized = (int) array_sum($carried);
            $accrual = $estimated - $recognized;
            if ($accrual <= 0) {
                continue;
            }

            $rows[] = [
                'booking' => $booking,
                'estimated_minor' => $estimated,
                'recognized_minor' => $recognized,
                'accrual_minor' => $accrual,
                'remaining_by_supplier' => $this->remainingBySupplier($booking, $estimated, $carried),
                'already_accrued' => $period !== null && $this->existingEntry($booking, $period) !== null,
            ];
        }

        return $rows;
    }

    /**
     * Post the accruals of a period for a branch. Re-running posts only what is missing.
     *
     * @return array{status: string, entries: int, total_minor: int}
     */
    public function accrue(FiscalPeriod $period, Branch $branch, ?int $actorId = null): array
    {
        if ($period->status === 'closed') {
            throw new DomainException('The period is closed; accruals cannot be posted into it.');
        }

        $cost = $this->account('cost_account_code', '5100');
        $accrued = $this->account('accrued_costs_account_code', '2170');
        $end = $period->ends_on->toDateString();
        $entries = 0;
        $total = 0;

        foreach ($this->preview($end, $branch, $period) as $row) {
            if ($row['already_accrued']) {
                continue;
            }
            /** @var Booking $booking */
            $booking = $row['booking'];
            $amount = $row['accrual_minor'];

            $this->posting->post(PostingRequest::make(
                entryDate: $end,
                branch: $branch,
                currency: $this->settings->baseCurrency(),
                lines: $this->lines($booking, $amount, $cost, $accrued, $row['remaining_by_supplier']),
                memo: "Cost accrual {$booking->number} {$period->name}",
                sourceType: 'booking',
                sourceId: $booking->id,
                event: self::EVENT.':'.$period->id,
                fxRate: '1',
                fxRateDate: $end,
                fxRateSource: 'accrual',
                actorId: $actorId,
            ));
            $entries++;
            $total += $amount;
        }

        if ($entries > 0) {
            $this->audit->log('cost_accrual.posted', $period, null, [
                'period' => $period->name,
                'branch_id' => $branch->id,
                'entries' => $entries,
                'total_minor' => $total,
            ], $actorId);
        }

        return ['status' => $entries > 0 ? 'posted' : 'nothing_to_accrue', 'entries' => $entries, 'total_minor' => $total];
    }

    /**
     * Reverse every accrual whose next period has begun and is open. Safe to run daily.
     *
     * @return int reversals posted
     */
    public function postDueReversals(?int $actorId = null, ?Carbon $asOf = null): int
    {
        $today = ($asOf ?? Carbon::today())->toDateString();
        $count = 0;

        $entries = JournalEntry::query()
            ->where('event', 'like', self::EVENT.':%')
            ->whereNull('reversal_of_id')
            ->where('status', 'posted')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as r')->whereColumn('r.reversal_of_id', 'journal_entries.id'))
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $date = $entry->entry_date->copy()->addDay()->toDateString();
            if ($today < $date) {
                continue;
            }
            $next = FiscalPeriod::query()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
            if (! $next || $next->status !== 'open') {
                continue; // stays pending until the next period exists and is open
            }

            $this->posting->reverse($entry, 'Cost accrual auto-reversal', $actorId, $date, 'cost_accrual_reversal:'.$entry->id);
            $count++;
        }

        return $count;
    }

    /**
     * What each supplier of the booking still has uninvoiced and unaccrued: its share of the
     * estimate (scaled to the base-currency estimate) minus the cost the ledger already carries
     * for that supplier. Key 0 stands for services without a supplier.
     *
     * @param  array<int, int>  $carried
     * @return array<int, int>
     */
    private function remainingBySupplier(Booking $booking, int $estimatedBase, array $carried): array
    {
        $weights = [];
        foreach ($booking->items as $item) {
            $key = $item->supplier_id ?? 0;
            $weights[$key] = ($weights[$key] ?? 0) + max(0, (int) $item->total_cost_minor);
        }
        $totalWeight = array_sum($weights);
        $remaining = [];
        $left = $estimatedBase;
        $last = array_key_last($weights);
        foreach ($weights as $supplierId => $weight) {
            $estimate = $supplierId === $last ? $left : ($totalWeight > 0 ? intdiv($estimatedBase * $weight, $totalWeight) : 0);
            $left -= $estimate;
            $remaining[$supplierId] = max(0, $estimate - (int) ($carried[$supplierId] ?? 0));
        }

        return $remaining;
    }

    /**
     * The accrual split across suppliers by what each still owes, so a supplier whose invoice is
     * already in the books gets none of it (GAP-RPT-05). The last share takes the rounding residue.
     *
     * @param  array<int, int>  $remaining
     * @return list<PostingLine>
     */
    private function lines(Booking $booking, int $amount, Account $cost, Account $accrued, array $remaining): array
    {
        $weights = array_filter($remaining);
        if ($weights === []) {
            $weights = [0 => 1];
        }
        $totalWeight = array_sum($weights);

        $lines = [];
        $left = $amount;
        $last = array_key_last($weights);
        foreach ($weights as $supplierId => $weight) {
            $share = $supplierId === $last ? $left : intdiv($amount * $weight, $totalWeight);
            $left -= $share;
            if ($share <= 0) {
                continue;
            }
            $type = $supplierId === 0 ? null : 'supplier';
            $id = $supplierId === 0 ? null : $supplierId;
            $lines[] = PostingLine::debit($cost, $share, $type, $id, 'Cost accrual '.$booking->number);
            $lines[] = PostingLine::credit($accrued, $share, $type, $id, 'Cost accrual '.$booking->number);
        }

        return $lines;
    }

    /**
     * Cost the ledger carries for this booking up to a date, per supplier (key 0 = unattributed),
     * base minor. Accruals of an earlier period and their reversals are left out: their reversal
     * is due on the first day of this period, so they must not suppress this period's accrual
     * whether or not the reversal job has run yet.
     *
     * @return array<int, int>
     */
    private function carriedCostBySupplier(Booking $booking, string $endDate, ?string $periodStart, array $costIds, string $costCode): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('invoices as inv', fn ($join) => $join->on('inv.id', '=', 'je.source_id')->whereRaw("je.source_type = 'invoice'"))
            ->leftJoin('accounting_documents as ad', fn ($join) => $join->on('ad.id', '=', 'je.source_id')->whereRaw("je.source_type = 'accounting_document'"))
            ->where('je.status', 'posted')
            ->where('a.type', 'expense')
            ->where(fn ($q) => $q->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'fiscal_year'))
            ->whereDate('je.entry_date', '<=', $endDate)
            ->whereRaw("COALESCE(CASE WHEN je.source_type = 'booking' THEN je.source_id END, inv.booking_id, ad.booking_id) = ?", [$booking->id])
            ->when($periodStart !== null, function ($query) use ($periodStart) {
                $earlier = fn ($sub) => $sub->select('id')->from('journal_entries')
                    ->where('event', 'like', self::EVENT.':%')->whereDate('entry_date', '<', $periodStart);
                $query->whereNotIn('je.id', $earlier)->whereNotIn(DB::raw('COALESCE(je.reversal_of_id, 0)'), $earlier);
            })
            ->groupBy('a.id', 'a.code', 'jl.party_type', 'jl.party_id')
            ->selectRaw('a.id as account_id, a.code, jl.party_type, jl.party_id, COALESCE(SUM(jl.base_debit_minor - jl.base_credit_minor), 0) as net')
            ->get();

        $bySupplier = [];
        foreach ($rows as $row) {
            if (isset($costIds[(int) $row->account_id]) || str_starts_with((string) $row->code, $costCode)) {
                $key = $row->party_type === 'supplier' && $row->party_id !== null ? (int) $row->party_id : 0;
                $bySupplier[$key] = ($bySupplier[$key] ?? 0) + (int) $row->net;
            }
        }

        return $bySupplier;
    }

    private function existingEntry(Booking $booking, FiscalPeriod $period): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', 'booking')->where('source_id', $booking->id)
            ->where('event', self::EVENT.':'.$period->id)
            ->first();
    }

    private function account(string $setting, string $default): Account
    {
        $code = (string) $this->settings->get('accounting', $setting, $default);
        $account = Account::query()->where('code', $code)->first();
        if (! $account) {
            throw new DomainException("Account {$code} does not exist. Create it in the chart of accounts (or run `php artisan accounting:ensure-accounts --apply`).");
        }

        return $account;
    }
}
