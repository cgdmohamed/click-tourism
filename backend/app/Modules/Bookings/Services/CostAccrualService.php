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
     * @return list<array{booking: Booking, estimated_minor: int, recognized_minor: int, accrual_minor: int, already_accrued: bool}>
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

            $recognized = $this->carriedCost($booking, $endDate, $costIds, $costCode);
            $accrual = $estimated - $recognized;
            if ($accrual <= 0) {
                continue;
            }

            $rows[] = [
                'booking' => $booking,
                'estimated_minor' => $estimated,
                'recognized_minor' => $recognized,
                'accrual_minor' => $accrual,
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
                lines: $this->lines($booking, $amount, $cost, $accrued),
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
     * The accrual split across the booking's suppliers by their estimated cost, so the ledger
     * filtered by supplier finds it (GAP-RPT-05). The last share takes the rounding residue.
     *
     * @return list<PostingLine>
     */
    private function lines(Booking $booking, int $amount, Account $cost, Account $accrued): array
    {
        $weights = [];
        foreach ($booking->items as $item) {
            $key = $item->supplier_id ?? 0;
            $weights[$key] = ($weights[$key] ?? 0) + max(0, (int) $item->total_cost_minor);
        }
        $weights = array_filter($weights);
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            $weights = [0 => 1];
            $totalWeight = 1;
        }

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

    /** Cost the ledger carries for this booking up to a date (base minor, nets any open accrual). */
    private function carriedCost(Booking $booking, string $endDate, array $costIds, string $costCode): int
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
            ->groupBy('a.id', 'a.code')
            ->selectRaw('a.id as account_id, a.code, COALESCE(SUM(jl.base_debit_minor - jl.base_credit_minor), 0) as net')
            ->get();

        $net = 0;
        foreach ($rows as $row) {
            if (isset($costIds[(int) $row->account_id]) || str_starts_with((string) $row->code, $costCode)) {
                $net += (int) $row->net;
            }
        }

        return $net;
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
