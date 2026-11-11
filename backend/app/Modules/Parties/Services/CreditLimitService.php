<?php

namespace App\Modules\Parties\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\PartyAccount;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Customer credit limit enforcement (`customers.credit_limit_minor`, 0 = no
 * limit) governed by `accounting.credit_limit_policy` = off|warn|block.
 *
 * Exposure, in base-currency minor units, is the net debit balance of the
 * customer's receivable subaccount (plus legacy lines on the control account
 * tagged with the customer), plus confirmed bookings whose revenue is not yet
 * in the ledger, plus the document being confirmed/issued.
 */
class CreditLimitService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    public function policy(): string
    {
        $policy = (string) $this->settings->get('accounting', 'credit_limit_policy', 'warn');

        return in_array($policy, ['off', 'warn', 'block'], true) ? $policy : 'warn';
    }

    /** Posted receivable balance (debits - credits) in base minor units. */
    public function ledgerBalance(Customer $customer): int
    {
        $control = Account::query()
            ->where('code', (string) $this->settings->get('accounting', 'ar_account_code', '1100'))
            ->first();
        if (! $control) {
            return 0;
        }

        $familyIds = $this->descendantIds($control);
        $subaccountIds = PartyAccount::query()
            ->where('party_type', 'customer')->where('party_id', $customer->id)
            ->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $familyIds)
            ->where(function ($query) use ($customer, $subaccountIds) {
                $query->where(function ($query) use ($customer) {
                    $query->where('jl.party_type', 'customer')->where('jl.party_id', $customer->id);
                });
                if ($subaccountIds !== []) {
                    $query->orWhereIn('jl.account_id', $subaccountIds);
                }
            })
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    /**
     * Confirmed, uncancelled bookings that are not yet reflected in the ledger
     * (no booking revenue entry and no issued linked invoice), in base minor units.
     */
    public function unpostedBookings(Customer $customer, ?int $exceptBookingId = null): int
    {
        $base = $this->settings->baseCurrency();
        $total = 0;

        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))->from('journal_entries as je')
                    ->whereColumn('je.source_id', 'bookings.id')
                    ->where('je.source_type', 'booking')->where('je.event', 'revenue')
                    ->whereNull('je.reversal_of_id');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))->from('invoices as i')
                    ->join('journal_entries as je', function ($join) {
                        $join->on('je.source_id', '=', 'i.id')
                            ->where('je.source_type', 'invoice')->where('je.event', 'issued');
                    })
                    ->whereColumn('i.booking_id', 'bookings.id')
                    ->whereNull('je.reversal_of_id');
            })
            ->get();

        foreach ($bookings as $booking) {
            $total += $this->toBase((int) $booking->total_sell_minor, $booking->currency_code, (string) $booking->fx_rate, $base);
        }

        return $total;
    }

    public function exposure(Customer $customer, ?int $exceptBookingId = null): int
    {
        return $this->ledgerBalance($customer) + $this->unpostedBookings($customer, $exceptBookingId);
    }

    /** Read-only summary for the customer profile. */
    public function summary(Customer $customer): array
    {
        $limit = (int) $customer->credit_limit_minor;

        return [
            'policy' => $this->policy(),
            'currency' => $this->settings->baseCurrency(),
            'credit_limit_minor' => $limit,
            'exposure_minor' => $this->exposure($customer),
        ];
    }

    public function toBase(int $amountMinor, string $currency, string $fxRate, ?string $base = null): int
    {
        $base ??= $this->settings->baseCurrency();
        if (strtoupper($currency) === strtoupper($base)) {
            return $amountMinor;
        }

        return (new Money($amountMinor, strtoupper($currency)))->convertTo($base, $fxRate)->amountMinor;
    }

    /**
     * Enforce the credit limit for a new commitment.
     *
     * @param  int  $addBaseMinor  amount being added, base currency
     * @param  Model  $subject  booking/invoice being confirmed/issued (audit target)
     * @return array<int, array<string, mixed>> warnings (empty when within limit/not applicable)
     *
     * @throws DomainException when the policy is `block` and the caller has no override
     */
    public function enforce(
        Customer $customer,
        int $addBaseMinor,
        Model $subject,
        string $action,
        ?int $actorId,
        ?int $exceptBookingId = null,
        bool $canOverride = false,
        ?string $overrideReason = null,
    ): array {
        $policy = $this->policy();
        $limit = (int) $customer->credit_limit_minor;
        if ($policy === 'off' || $limit <= 0) {
            return [];
        }

        $exposure = $this->exposure($customer, $exceptBookingId);
        $projected = $exposure + $addBaseMinor;
        if ($projected <= $limit) {
            return [];
        }

        $details = [
            'customer_id' => $customer->public_id,
            'action' => $action,
            'policy' => $policy,
            'currency' => $this->settings->baseCurrency(),
            'credit_limit_minor' => $limit,
            'current_exposure_minor' => $exposure,
            'added_minor' => $addBaseMinor,
            'projected_exposure_minor' => $projected,
        ];

        if ($policy === 'block') {
            if ($canOverride && $overrideReason !== null && trim($overrideReason) !== '') {
                $this->audit->log('customer.credit_limit_overridden', $subject, null, $details + ['override_reason' => trim($overrideReason)], $actorId);

                return [$this->warning($details, true)];
            }

            throw new DomainException(sprintf(
                'Credit limit exceeded: limit %d, current exposure %d, this %s adds %d (projected %d) in %s minor units.',
                $limit, $exposure, $action, $addBaseMinor, $projected, $details['currency'],
            ));
        }

        $this->audit->log('customer.credit_limit_exceeded', $subject, null, $details, $actorId);

        return [$this->warning($details, false)];
    }

    /** @param  array<string, mixed>  $details */
    private function warning(array $details, bool $overridden): array
    {
        return [
            'code' => 'credit_limit_exceeded',
            'overridden' => $overridden,
            'message' => 'Customer credit limit exceeded.',
        ] + $details;
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
}
