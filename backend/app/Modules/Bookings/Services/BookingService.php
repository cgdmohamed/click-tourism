<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingItem;
use App\Modules\Bookings\Models\BookingStatusHistory;
use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\CreditLimitService;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Tax\Services\TaxPolicy;
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

    /**
     * Statuses a booking may move to from $status. Single source of truth for
     * the transition guard, the API payload and the UI.
     *
     * @return string[]
     */
    public static function allowedTransitionsFor(?string $status): array
    {
        return self::TRANSITIONS[$status ?? ''] ?? [];
    }

    public function __construct(
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly FxService $fx,
        private readonly PostingService $posting,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $ledger,
        private readonly CreditLimitService $creditLimits,
        private readonly TaxPolicy $taxPolicy,
        private readonly CommissionService $commissions,
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

            $departure = null;
            if (! empty($data['tour_departure_id'])) {
                $departure = TourDeparture::query()->where('public_id', $data['tour_departure_id'])->first();
                if (! $departure || $departure->branch_id !== $branch->id || $departure->status !== 'open') {
                    throw new DomainException('The selected tour departure is not available for this branch.');
                }
                if ($departure->starts_on->toDateString() !== ($data['start_date'] ?? null)) {
                    throw new DomainException('Booking start date must match the selected departure.');
                }
            }

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
                'tour_departure_id' => $departure?->id,
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

    /**
     * Edit a booking before anything financial exists for it.
     *
     * Confirmed bookings are corrected with adjustment documents, not edits
     * (docs/product/workflows.md), so this only accepts draft/quoted bookings
     * that have no payments, invoices, obligations or journal entries.
     */
    public function update(Booking $booking, array $data, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $data, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($actorId !== null) {
                $user = User::query()->findOrFail($actorId);
                $this->branchScope->assertBranch($user, $booking->branch);
            }

            if (! in_array($booking->status, ['draft', 'quoted'], true)) {
                throw new DomainException('Only draft or quoted bookings can be edited. Use an adjustment document for a confirmed booking.');
            }

            $itemIds = $booking->items()->pluck('id');
            $hasFinancials = PaymentAllocation::query()->where('booking_id', $booking->id)->exists()
                || Invoice::query()->where('booking_id', $booking->id)->exists()
                || JournalEntry::query()
                    ->where('source_type', 'booking')
                    ->where('source_id', $booking->id)
                    ->exists()
                || DB::table('supplier_obligations')->whereIn('booking_item_id', $itemIds)->exists();

            if ($hasFinancials) {
                throw new DomainException('This booking already has payments, invoices, obligations or journal entries. Use an adjustment document instead of editing it.');
            }

            $before = [
                'customer_id' => $booking->customer?->public_id,
                'start_date' => $booking->start_date->toDateString(),
                'end_date' => $booking->end_date?->toDateString(),
                'currency' => $booking->currency_code,
                'total_sell_minor' => (int) $booking->total_sell_minor,
                'total_cost_minor' => (int) $booking->total_cost_minor,
                'tax_minor' => (int) $booking->tax_minor,
                'items' => $booking->items->map(fn (BookingItem $item) => [
                    'service_type' => $item->service_type,
                    'quantity' => (int) $item->quantity,
                    'unit_cost_minor' => (int) $item->unit_cost_minor,
                    'unit_sell_minor' => (int) $item->unit_sell_minor,
                ])->all(),
            ];

            $customer = $booking->customer;
            if (! empty($data['customer_id'])) {
                $customer = Customer::query()->where('public_id', $data['customer_id'])->first();
                if (! $customer) {
                    throw new DomainException('Customer not found.');
                }
            }

            $currency = strtoupper((string) ($data['currency'] ?? $booking->currency_code));
            $startDate = (string) ($data['start_date'] ?? $booking->start_date->toDateString());
            $fxRate = isset($data['fx_rate']) && $data['fx_rate'] !== ''
                ? (string) $data['fx_rate']
                : ($currency === $booking->currency_code
                    ? (string) $booking->fx_rate
                    : $this->fx->rateFor($currency, $startDate));

            $attributes = [
                'customer_id' => $customer?->id ?? $booking->customer_id,
                'start_date' => $startDate,
                'end_date' => $data['end_date'] ?? $booking->end_date?->toDateString(),
                'pax_adults' => max(1, (int) ($data['pax_adults'] ?? $booking->pax_adults)),
                'pax_children' => max(0, (int) ($data['pax_children'] ?? $booking->pax_children)),
                'pax_infants' => max(0, (int) ($data['pax_infants'] ?? $booking->pax_infants)),
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $startDate,
                'fx_rate_source' => isset($data['fx_rate']) && $data['fx_rate'] !== '' ? 'manual' : $booking->fx_rate_source,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $booking->notes,
            ];

            if (array_key_exists('items', $data) && is_array($data['items'])) {
                $items = $data['items'];
                if (count($items) === 0) {
                    throw new DomainException('A booking needs at least one service item.');
                }

                $prepared = [];
                $totalSell = 0;
                $totalCost = 0;
                $totalTax = 0;
                foreach ($items as $item) {
                    $row = $this->prepareItem($booking, $item, $currency);
                    $prepared[] = $row;
                    $totalSell += $row['total_sell_minor'];
                    $totalCost += $row['total_cost_minor'];
                    $totalTax += $row['tax_minor'];
                }

                $booking->items()->delete();
                foreach ($prepared as $row) {
                    BookingItem::query()->create($row);
                }

                $attributes['total_sell_minor'] = $totalSell;
                $attributes['total_cost_minor'] = $totalCost;
                $attributes['tax_minor'] = $totalTax;
            }

            $booking->update($attributes);

            $this->audit->log('booking.updated', $booking, $before, [
                'customer_id' => $booking->customer?->public_id,
                'start_date' => $booking->start_date->toDateString(),
                'end_date' => $booking->end_date?->toDateString(),
                'currency' => $booking->currency_code,
                'total_sell_minor' => (int) $booking->total_sell_minor,
                'total_cost_minor' => (int) $booking->total_cost_minor,
                'tax_minor' => (int) $booking->tax_minor,
                'items' => $booking->items()->get()->map(fn (BookingItem $item) => [
                    'service_type' => $item->service_type,
                    'quantity' => (int) $item->quantity,
                    'unit_cost_minor' => (int) $item->unit_cost_minor,
                    'unit_sell_minor' => (int) $item->unit_sell_minor,
                ])->all(),
            ], $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function transition(Booking $booking, string $toStatus, ?string $reason, ?int $actorId = null, ?string $overrideReason = null, bool $canOverrideCreditLimit = false): Booking
    {
        return DB::transaction(function () use ($booking, $toStatus, $reason, $actorId, $overrideReason, $canOverrideCreditLimit) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $from = $booking->status;
            if ($toStatus === 'cancelled') {
                $this->assertNoIssuedCustomerInvoice($booking);
            }

            $allowed = self::allowedTransitionsFor($from);
            if (! in_array($toStatus, $allowed, true)) {
                throw new DomainException("Cannot move a booking from {$from} to {$toStatus}.");
            }

            if ($actorId !== null) {
                $user = User::query()->findOrFail($actorId);
                $this->branchScope->assertBranch($user, $booking->branch);
            }

            if ($toStatus === 'confirmed'
                && $booking->marginMinor() < 0
                && $this->settings->bool('accounting', 'negative_margin_requires_approval', true)
                && $booking->margin_approval_status !== 'approved') {
                throw new DomainException('Negative-margin booking requires manager approval before confirmation.');
            }

            if ($toStatus === 'confirmed' && $booking->tour_departure_id !== null) {
                // Lock the common departure row to serialize concurrent confirmations.
                $departure = TourDeparture::query()->lockForUpdate()->findOrFail($booking->tour_departure_id);
                if ($departure->status !== 'open') {
                    throw new DomainException('This departure is closed for new bookings.');
                }
                $seats = (int) $booking->pax_adults + (int) $booking->pax_children + (int) $booking->pax_infants;
                if ($departure->capacity !== null && $departure->occupiedSeats() + $seats > $departure->capacity) {
                    throw new DomainException('Not enough seats remain on the selected departure.');
                }
            }

            $warnings = [];
            if ($toStatus === 'confirmed') {
                $warnings = $this->creditLimits->enforce(
                    $booking->customer,
                    $this->creditLimits->toBase((int) $booking->total_sell_minor, $booking->currency_code, (string) $booking->fx_rate),
                    $booking,
                    'booking confirmation',
                    $actorId,
                    $booking->id,
                    $canOverrideCreditLimit,
                    $overrideReason,
                );
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
            $this->commissions->syncBooking($booking, $actorId);

            return $booking->refresh()->load('items', 'customer')->withWarnings($warnings);
        });
    }

    public function requestMarginApproval(Booking $booking, string $reason, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->marginMinor() >= 0) {
                throw new DomainException('Margin approval is only required for a negative-margin booking.');
            }
            if (! in_array($booking->status, ['draft', 'quoted'], true)) {
                throw new DomainException('Only draft or quoted bookings can request margin approval.');
            }
            if (trim($reason) === '') {
                throw new DomainException('A margin approval reason is required.');
            }

            $before = $booking->only(['margin_approval_status', 'margin_approval_reason']);
            $booking->update([
                'margin_approval_status' => 'pending',
                'margin_approval_reason' => trim($reason),
                'margin_requested_by' => $actorId,
                'margin_requested_at' => now(),
                'margin_approved_by' => null,
                'margin_approved_at' => null,
            ]);
            $this->audit->log('booking.margin_approval_requested', $booking, $before, [
                'margin_minor' => $booking->marginMinor(),
                'reason' => trim($reason),
            ], $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function approveMargin(Booking $booking, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->margin_approval_status !== 'pending') {
                throw new DomainException('The booking has no pending margin approval request.');
            }
            if ($actorId !== null && $booking->margin_requested_by === $actorId) {
                throw new DomainException('The requester cannot approve their own negative-margin booking.');
            }

            $booking->update([
                'margin_approval_status' => 'approved',
                'margin_approved_by' => $actorId,
                'margin_approved_at' => now(),
            ]);
            $this->audit->log('booking.margin_approved', $booking, ['margin_approval_status' => 'pending'], [
                'margin_approval_status' => 'approved',
                'margin_minor' => $booking->marginMinor(),
            ], $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    private function assertNoIssuedCustomerInvoice(Booking $booking): void
    {
        if (Invoice::query()->where('booking_id', $booking->id)
            ->where('type', Invoice::TYPE_CUSTOMER_INVOICE)
            ->where('status', Invoice::STATUS_ISSUED)->exists()) {
            throw new DomainException('This booking has an issued invoice. Reconcile the invoice before cancelling the booking.');
        }
    }

    public function cancel(Booking $booking, ?string $reason, int $cancellationFeeMinor = 0, ?int $actorId = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $cancellationFeeMinor, $actorId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (in_array($booking->status, ['cancelled', 'closed'], true)) {
                throw new DomainException('This booking can no longer be cancelled.');
            }

            $this->assertNoIssuedCustomerInvoice($booking);
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
            $this->commissions->syncBooking($booking, $actorId);

            return $booking->refresh()->load('items', 'customer');
        });
    }

    public function postRevenue(Booking $booking, ?int $actorId = null): void
    {
        if ($this->revenueAlreadyRecognizedForBooking($booking)) {
            return;
        }

        $ar = $this->ledger->receivable($booking->customer_id);
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

    /**
     * Returns true if revenue has already been recognized for this booking,
     * either through a booking-sourced revenue entry or through an issued
     * invoice linked to this booking. Prevents double revenue recognition
     * when both a booking confirmation and an invoice are posted for the
     * same economic event (DEC-001, DEC-028).
     */
    private function revenueAlreadyRecognizedForBooking(Booking $booking): bool
    {
        // Booking-sourced revenue entry already posted.
        $bookingRevenue = JournalEntry::query()
            ->where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('event', 'revenue')
            ->where('reversal_of_id', null)
            ->exists();

        if ($bookingRevenue) {
            return true;
        }

        // Invoice-sourced entry already posted for an invoice linked to this booking.
        $invoiceRevenue = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('event', 'issued')
            ->where('reversal_of_id', null)
            ->whereExists(function ($query) use ($booking) {
                $query->select('id')
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'journal_entries.source_id')
                    ->where('invoices.booking_id', $booking->id);
            })
            ->exists();

        return $invoiceRevenue;
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

        // Same policy as the revenue guard: skip silently when an issued
        // supplier invoice linked to this booking already recognized the cost.
        if ($this->costAlreadyRecognizedBySupplierInvoice($booking)) {
            return;
        }

        $cost = $this->posting->account((string) $this->settings->get('accounting', 'cost_account_code', '5100'));
        // Credit each supplier's own payable subaccount; items without a
        // supplier stay on the payables control account.
        $control = $this->posting->account((string) $this->settings->get('accounting', 'ap_account_code', '2100'));
        $bySupplier = [];
        foreach ($booking->items as $item) {
            $key = $item->supplier_id ?? 0;
            $bySupplier[$key] = ($bySupplier[$key] ?? 0) + (int) $item->total_cost_minor;
        }
        $lines = [];
        $costed = 0;
        foreach ($bySupplier as $supplierId => $amount) {
            if ($amount <= 0) {
                continue;
            }
            // GAP-RPT-05: each supplier's share of the cost carries that supplier, so the ledger
            // filtered by supplier finds the cost as well as the payable.
            $lines[] = PostingLine::debit($cost, $amount, $supplierId === 0 ? null : 'supplier', $supplierId === 0 ? null : $supplierId, 'Booking cost '.$booking->number);
            $costed += $amount;
        }
        if ($costed < (int) $booking->total_cost_minor) {
            $lines[] = PostingLine::debit($cost, (int) $booking->total_cost_minor - $costed, null, null, 'Booking cost '.$booking->number);
        }
        foreach ($bySupplier as $supplierId => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $lines[] = $supplierId === 0
                ? PostingLine::credit($control, $amount, null, null, 'Supplier cost '.$booking->number)
                : PostingLine::credit($this->ledger->payable($supplierId), $amount, 'supplier', $supplierId, 'Supplier cost '.$booking->number);
        }

        $this->posting->post(PostingRequest::make(
            entryDate: $booking->completed_at?->toDateString() ?? $booking->start_date->toDateString(),
            branch: $booking->branch,
            currency: $booking->currency_code,
            lines: $lines,
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

    /**
     * True when an issued, un-reversed supplier invoice linked to this booking
     * already posted the supplier cost (Dr expense / Cr payable).
     */
    private function costAlreadyRecognizedBySupplierInvoice(Booking $booking): bool
    {
        return JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('event', 'issued')
            ->whereNull('reversal_of_id')
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('journal_entries as reversals')
                    ->whereColumn('reversals.reversal_of_id', 'journal_entries.id');
            })
            ->whereExists(function ($query) use ($booking) {
                $query->select('id')
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'journal_entries.source_id')
                    ->where('invoices.booking_id', $booking->id)
                    ->where('invoices.type', 'supplier_invoice');
            })
            ->exists();
    }

    private function postCancellationFee(Booking $booking, ?int $actorId): void
    {
        $ar = $this->ledger->receivable($booking->customer_id);
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

        // One tax per installation (settings tax.*). A tax_profile_id sent by an
        // old client is ignored; new items never reference a profile.
        $enabled = $this->taxPolicy->enabled();
        $rate = $enabled ? $this->taxPolicy->rate() : '0';
        $inclusive = $enabled && $this->taxPolicy->inclusive();
        $tax = $this->taxPolicy->taxOn($grossSell);

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
            'tax_profile_id' => null,
            'tax_rate_snapshot' => $rate,
            'tax_minor' => $tax,
            'total_sell_minor' => $grossSell + ($inclusive ? 0 : $tax),
            'total_cost_minor' => $grossCost,
        ];
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
