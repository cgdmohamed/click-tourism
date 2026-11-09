<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingItem;
use App\Modules\Bookings\Models\BookingStatusHistory;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Tax\Models\TaxProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Booking lifecycle and revenue/cost posting intent (PRD FR-BOOK-*).
 *
 * The accounting trigger is a setting flagged PENDING ACCOUNTANT APPROVAL:
 * `accounting.booking_posting_trigger` = confirm|complete|manual.
 */
class BookingService
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        'draft' => ['quoted', 'confirmed', 'cancelled'],
        'quoted' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['in_progress', 'completed', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'cancelled', 'no_show'],
        'completed' => ['closed'],
        'closed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly FxService $fx,
        private readonly PostingService $posting,
        private readonly AuditService $audit,
    ) {}

    public function create(array $data, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($data, $actorId) {
            $branch = $this->branch($data['branch_id'] ?? null);
            if ($actorId !== null) {
                $user = User::query()->findOrFail($actorId);
                $this->branchScope->assertBranch($user, $branch);
            }

            $customer = Customer::query()->where('public_id', $data['customer_id'] ?? '')->first();
            if (! $customer) {
                throw new DomainException('Customer not found.');
            }

            $owner = ! empty($data['owner_user_id'])
                ? User::query()->where('public_id', $data['owner_user_id'])->first()
                : null;

            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $startDate = $data['start_date'] ?? now()->toDateString();
            $fxRate = (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $startDate));

            $items = $data['items'] ?? [];
            if (count($items) === 0) {
                throw new DomainException('A booking needs at least one service item.');
            }

            $booking = Booking::query()->create([
                'number' => $this->sequences->next('booking', $branch),
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'owner_user_id' => $owner?->id,
                'status' => 'draft',
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $startDate,
                'fx_rate_source' => 'manual',
                'start_date' => $startDate,
                'end_date' => $data['end_date'] ?? null,
                'pax_adults' => max(1, (int) ($data['pax_adults'] ?? 1)),
                'pax_children' => max(0, (int) ($data['pax_children'] ?? 0)),
                'pax_infants' => max(0, (int) ($data['pax_infants'] ?? 0)),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $totalSell = 0;
            $totalCost = 0;
            $totalTax = 0;

            foreach ($items as $item) {
                $prepared = $this->prepareItem($booking, $item, $currency);
                BookingItem::query()->create($prepared);
                $totalSell += $prepared['total_sell_minor'];
                $totalCost += $prepared['total_cost_minor'];
                $totalTax += $prepared['tax_minor'];
            }

            $booking->update([
                'total_sell_minor' => $totalSell,
                'total_cost_minor' => $totalCost,
                'tax_minor' => $totalTax,
            ]);

            $this->history($booking, null, 'draft', 'Booking created', $actorId);
            $this->audit->log('booking.created', $booking, null, $booking->toApiArray(), $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function transition(Booking $booking, string $toStatus, ?string $reason, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $toStatus, $reason, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $from = $booking->status;

            $allowed = self::TRANSITIONS[$from] ?? [];
            if (! in_array($toStatus, $allowed, true)) {
                throw new DomainException("Cannot move a booking from {$from} to {$toStatus}.");
            }

            if ($actorId !== null) {
                $user = User::query()->findOrFail($actorId);
                $this->branchScope->assertBranch($user, $booking->branch);
            }

            $attributes = ['status' => $toStatus];

            match ($toStatus) {
                'confirmed' => $attributes['confirmed_at'] = now(),
                'completed' => $attributes['completed_at'] = now(),
                'cancelled' => $attributes['cancelled_at'] = now(),
                default => null,
            };

            $booking->update($attributes);
            $this->history($booking, $from, $toStatus, $reason, $actorId);

            $trigger = (string) $this->settings->get('accounting', 'booking_posting_trigger', 'confirm');
            $autoPost = $this->settings->bool('accounting', 'auto_post_bookings', true);

            if ($autoPost) {
                if ($toStatus === 'confirmed' && $trigger === 'confirm') {
                    $this->postRevenue($booking, $actorId);
                }

                if ($toStatus === 'completed') {
                    if ($trigger === 'complete') {
                        $this->postRevenue($booking, $actorId);
                    }
                    $this->postCost($booking, $actorId);
                }

                if ($toStatus === 'cancelled') {
                    $this->reverseBookingEntries($booking, 'Booking cancelled: '.($reason ?? 'no reason'), $actorId);
                }
            }

            $this->audit->log('booking.transitioned', $booking, ['status' => $from], ['status' => $toStatus, 'reason' => $reason], $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function cancel(Booking $booking, ?string $reason, int $cancellationFeeMinor = 0, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $cancellationFeeMinor, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (in_array($booking->status, ['cancelled', 'closed'], true)) {
                throw new DomainException('This booking can no longer be cancelled.');
            }

            $from = $booking->status;
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancellation_fee_minor' => max(0, $cancellationFeeMinor),
            ]);

            $this->history($booking, $from, 'cancelled', $reason, $actorId);
            $this->reverseBookingEntries($booking, 'Booking cancelled: '.($reason ?? 'no reason'), $actorId);

            if ($cancellationFeeMinor > 0) {
                $this->postCancellationFee($booking, $actorId);
            }

            $this->audit->log('booking.cancelled', $booking, ['status' => $from], [
                'reason' => $reason,
                'cancellation_fee_minor' => $cancellationFeeMinor,
            ], $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function postRevenue(Booking $booking, ?int $actorId = null): void
    {
        $alreadyPosted = $booking->status !== 'cancelled' && JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'revenue')
            ->where('reversal_of_id', null)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $ar = $this->posting->account((string) $this->settings->get('accounting', 'ar_account_code', '1100'));
        $revenue = $this->posting->account((string) $this->settings->get('accounting', 'revenue_account_code', '4100'));
        $taxPayable = $this->posting->account((string) $this->settings->get('accounting', 'tax_payable_account_code', '2200'));

        $net = $booking->total_sell_minor - $booking->tax_minor;
        $lines = [
            PostingLine::debit($ar, $booking->total_sell_minor, 'customer', $booking->customer_id, 'Booking '.$booking->number),
            PostingLine::credit($revenue, $net, null, null, 'Booking revenue '.$booking->number),
        ];

        if ($booking->tax_minor > 0) {
            $lines[] = PostingLine::credit($taxPayable, $booking->tax_minor, null, null, 'Tax on '.$booking->number);
        }

        $this->posting->post(PostingRequest::make(
            entryDate: $booking->confirmed_at?->toDateString() ?? $booking->start_date->toDateString(),
            branch: $booking->branch,
            currency: $booking->currency_code,
            lines: $lines,
            memo: 'Booking revenue '.$booking->number,
            sourceType: 'booking',
            sourceId: $booking->id,
            event: 'revenue',
            fxRate: (string) $booking->fx_rate,
            fxRateDate: $booking->fx_rate_date,
            fxRateSource: $booking->fx_rate_source,
            actorId: $actorId,
        ));
    }

    public function postCost(Booking $booking, ?int $actorId = null): void
    {
        if ($booking->total_cost_minor <= 0) {
            return;
        }

        $exists = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'cost')
            ->where('reversal_of_id', null)
            ->exists();

        if ($exists) {
            return;
        }

        $cost = $this->posting->account((string) $this->settings->get('accounting', 'cost_account_code', '5100'));
        $ap = $this->posting->account((string) $this->settings->get('accounting', 'ap_account_code', '2100'));

        $this->posting->post(PostingRequest::make(
            entryDate: $booking->completed_at?->toDateString() ?? $booking->start_date->toDateString(),
            branch: $booking->branch,
            currency: $booking->currency_code,
            lines: [
                PostingLine::debit($cost, $booking->total_cost_minor, null, null, 'Booking cost '.$booking->number),
                PostingLine::credit($ap, $booking->total_cost_minor, null, null, 'Supplier cost '.$booking->number),
            ],
            memo: 'Booking cost '.$booking->number,
            sourceType: 'booking',
            sourceId: $booking->id,
            event: 'cost',
            fxRate: (string) $booking->fx_rate,
            fxRateDate: $booking->fx_rate_date,
            fxRateSource: $booking->fx_rate_source,
            actorId: $actorId,
        ));
    }

    private function postCancellationFee(Booking $booking, ?int $actorId): void
    {
        $ar = $this->posting->account((string) $this->settings->get('accounting', 'ar_account_code', '1100'));
        $revenue = $this->posting->account((string) $this->settings->get('accounting', 'revenue_account_code', '4100'));

        $this->posting->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $booking->branch,
            currency: $booking->currency_code,
            lines: [
                PostingLine::debit($ar, $booking->cancellation_fee_minor, 'customer', $booking->customer_id, 'Cancellation fee '.$booking->number),
                PostingLine::credit($revenue, $booking->cancellation_fee_minor, null, null, 'Cancellation fee '.$booking->number),
            ],
            memo: 'Cancellation fee '.$booking->number,
            sourceType: 'booking',
            sourceId: $booking->id,
            event: 'cancellation_fee',
            fxRate: (string) $booking->fx_rate,
            fxRateDate: $booking->fx_rate_date,
            fxRateSource: $booking->fx_rate_source,
            actorId: $actorId,
        ));
    }

    private function reverseBookingEntries(Booking $booking, string $reason, ?int $actorId): void
    {
        $entries = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->whereNull('reversal_of_id')
            ->where('status', 'posted')
            ->get();

        foreach ($entries as $entry) {
            $alreadyReversed = JournalEntry::query()
                ->where('reversal_of_id', $entry->id)
                ->exists();

            if (! $alreadyReversed) {
                $this->posting->reverse($entry, $reason, $actorId);
            }
        }
    }

    /** @return array<string, mixed> */
    private function prepareItem(Booking $booking, array $item, string $currency): array
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitCost = max(0, (int) ($item['unit_cost_minor'] ?? 0));
        $unitSell = max(0, (int) ($item['unit_sell_minor'] ?? 0));
        $grossSell = $quantity * $unitSell;
        $grossCost = $quantity * $unitCost;

        $taxProfile = ! empty($item['tax_profile_id'])
            ? TaxProfile::query()->find($item['tax_profile_id'])
            : null;

        $rate = $taxProfile ? (string) $taxProfile->rate : '0';
        $inclusive = $taxProfile?->is_inclusive ?? false;
        $tax = $this->taxFor($grossSell, $rate, $inclusive);

        $supplier = ! empty($item['supplier_id'])
            ? Supplier::query()->where('public_id', $item['supplier_id'])->first()
            : null;

        return [
            'booking_id' => $booking->id,
            'service_type' => $item['service_type'] ?? 'other',
            'supplier_id' => $supplier?->id,
            'description' => $item['description'] ?? 'Service',
            'starts_on' => $item['starts_on'] ?? null,
            'ends_on' => $item['ends_on'] ?? null,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCost,
            'unit_sell_minor' => $unitSell,
            'cost_currency' => strtoupper($item['cost_currency'] ?? $currency),
            'sell_currency' => strtoupper($item['sell_currency'] ?? $currency),
            'tax_profile_id' => $taxProfile?->id,
            'tax_rate_snapshot' => $rate,
            'tax_minor' => $tax,
            'total_sell_minor' => $grossSell + ($inclusive ? 0 : $tax),
            'total_cost_minor' => $grossCost,
        ];
    }

    private function taxFor(int $grossMinor, string $rate, bool $inclusive): int
    {
        if (bccomp($rate, '0', 4) <= 0) {
            return 0;
        }

        $divisor = $inclusive ? bcadd('100', $rate, 6) : '100';
        $raw = bcdiv(bcmul((string) $grossMinor, $rate, 8), $divisor, 8);

        return (int) bcdiv(bcadd($raw, '0.5', 8), '1', 0);
    }

    private function history(Booking $booking, ?string $from, string $to, ?string $reason, ?int $actorId): void
    {
        BookingStatusHistory::query()->create([
            'booking_id' => $booking->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function branch(?string $publicId): Branch
    {
        $branch = $publicId ? Branch::query()->where('public_id', $publicId)->first() : null;
        if (! $branch) {
            throw new DomainException('Branch not found.');
        }

        return $branch;
    }
}
