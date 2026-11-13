<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Services\ReportService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * DEC-002: the booking's estimated cost next to what the books have actually recognized.
 *
 * Everything is in the base currency and read from the same posted journal lines the
 * profitability report uses, so the booking view and the reports cannot disagree. Estimates
 * come from the booking's own items and are never posted; a draft supplier invoice has no
 * journal lines, so it changes nothing here.
 */
class BookingCostSummary
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly ReportService $reports,
    ) {}

    /** @return array<string, mixed> */
    public function for(Booking $booking): array
    {
        $base = $this->settings->baseCurrency();
        $fx = (string) ($booking->fx_rate ?: '1');
        $toBase = fn (int $minor): int => strtoupper((string) $booking->currency_code) === $base
            ? $minor
            : (new Money($minor, (string) $booking->currency_code))->convertTo($base, $fx)->amountMinor;

        $estimatedCost = $toBase((int) $booking->total_cost_minor);
        $estimatedRevenue = $toBase((int) $booking->total_sell_minor - (int) $booking->tax_minor);

        [$revenue, $cost] = $this->ledgerTotals($booking);
        $accrued = $this->accrued($booking);

        return [
            'currency' => $base,
            'estimated_cost_minor' => $estimatedCost,
            'recognized_cost_minor' => $cost,
            'accrued_cost_minor' => $accrued,
            'expected_remaining_minor' => max(0, $estimatedCost - $cost),
            'estimated_revenue_minor' => $estimatedRevenue,
            'recognized_revenue_minor' => $revenue,
            'projected_profit_minor' => $estimatedRevenue - $estimatedCost,
            'recognized_profit_minor' => $revenue - $cost,
            'items' => $this->invoicedByItem($booking),
        ];
    }

    /** Posted revenue and direct cost carrying this booking, base minor units (nets reversals). */
    private function ledgerTotals(Booking $booking): array
    {
        $costIds = $this->reports->costOfServicesAccountIds();
        $costCode = $this->reports->costAccountCode();

        $rows = $this->bookingLines($booking)
            ->groupBy('a.id', 'a.code', 'a.type')
            ->selectRaw('a.id as account_id, a.code, a.type')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->get();

        $revenue = 0;
        $cost = 0;
        foreach ($rows as $row) {
            if ($row->type === 'revenue') {
                $revenue += (int) $row->credit - (int) $row->debit;
            } elseif (isset($costIds[(int) $row->account_id]) || str_starts_with((string) $row->code, $costCode)) {
                $cost += (int) $row->debit - (int) $row->credit;
            }
        }

        return [$revenue, $cost];
    }

    /** Net cost still carried by period-end accrual entries (the entry plus its reversal). */
    private function accrued(Booking $booking): int
    {
        $costIds = $this->reports->costOfServicesAccountIds();
        $rows = $this->bookingLines($booking)
            ->where(function ($query) {
                $query->where('je.event', 'like', CostAccrualService::EVENT.':%')
                    ->orWhereIn('je.reversal_of_id', fn ($sub) => $sub->select('id')->from('journal_entries')->where('event', 'like', CostAccrualService::EVENT.':%'));
            })
            ->groupBy('a.id')
            ->selectRaw('a.id as account_id, COALESCE(SUM(jl.base_debit_minor - jl.base_credit_minor), 0) as net')
            ->get();

        $net = 0;
        foreach ($rows as $row) {
            if (isset($costIds[(int) $row->account_id])) {
                $net += (int) $row->net;
            }
        }

        return $net;
    }

    private function bookingLines(Booking $booking)
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('invoices as inv', fn ($join) => $join->on('inv.id', '=', 'je.source_id')->whereRaw("je.source_type = 'invoice'"))
            ->leftJoin('accounting_documents as ad', fn ($join) => $join->on('ad.id', '=', 'je.source_id')->whereRaw("je.source_type = 'accounting_document'"))
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->where(fn ($q) => $q->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'fiscal_year'))
            ->whereRaw("COALESCE(CASE WHEN je.source_type = 'booking' THEN je.source_id END, inv.booking_id, ad.booking_id) = ?", [$booking->id]);
    }

    /**
     * Cost recognized per service, from the issued supplier invoice lines that name it (base minor).
     *
     * @return list<array{id: string, invoiced_cost_minor: int}>
     */
    private function invoicedByItem(Booking $booking): array
    {
        $base = $this->settings->baseCurrency();
        $rows = DB::table('invoice_lines as il')
            ->join('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->where('inv.booking_id', $booking->id)
            ->where('inv.type', 'supplier_invoice')
            ->where('inv.status', 'issued')
            ->whereNotNull('il.booking_item_id')
            ->get(['il.booking_item_id', 'il.debit_minor', 'il.credit_minor', 'inv.currency_code', 'inv.fx_rate']);

        $byItem = [];
        foreach ($rows as $row) {
            $amount = (int) $row->debit_minor - (int) $row->credit_minor;
            $currency = strtoupper((string) $row->currency_code);
            $baseAmount = $currency === $base ? $amount : (new Money($amount, $currency))->convertTo($base, (string) $row->fx_rate)->amountMinor;
            $byItem[(int) $row->booking_item_id] = ($byItem[(int) $row->booking_item_id] ?? 0) + $baseAmount;
        }

        $out = [];
        foreach ($booking->items as $item) {
            $out[] = ['id' => (string) $item->id, 'invoiced_cost_minor' => $byItem[$item->id] ?? 0];
        }

        return $out;
    }
}
