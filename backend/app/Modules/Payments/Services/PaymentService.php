<?php

namespace App\Modules\Payments\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Accounting\Services\RealizedFxService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\NotificationService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\Refund;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts, allocation to bookings, and refunds (PRD FR-PAY-*).
 * Every state-changing call is idempotent and posts exactly one journal
 * entry per event (ADR-0004, ADR-0006).
 */
class PaymentService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly FxService $fx,
        private readonly PostingService $posting,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $ledger,
        private readonly RealizedFxService $realizedFx,
        private readonly CommissionService $commissions,
        private readonly NotificationService $notifications,
    ) {}

    public function record(array $data, ?int $actorId = null, ?string $idempotencyKey = null): Payment
    {
        return DB::transaction(function () use ($data, $actorId, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load('allocations');
                }
            }

            $branch = $this->branch($data['branch_id'] ?? null);
            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            $customer = Customer::query()->where('public_id', $data['customer_id'] ?? '')->first();
            if (! $customer) {
                throw new DomainException('Customer not found.');
            }

            $cashAccount = Account::query()->where('public_id', $data['cash_account_id'] ?? '')->first();
            if (! $cashAccount || ! $cashAccount->is_active || ! $cashAccount->is_postable) {
                throw new DomainException('Cash account not found or not postable.');
            }

            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Payment amount must be greater than zero.');
            }

            $currency = strtoupper($data['currency'] ?? $this->settings->baseCurrency());
            $paidAt = $data['paid_at'] ?? now()->toIso8601String();
            $fxRate = (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, substr((string) $paidAt, 0, 10)));

            $booking = ! empty($data['booking_id'])
                ? Booking::query()->where('public_id', $data['booking_id'])->first()
                : null;
            if (! empty($data['booking_id']) && ! $booking) {
                throw new DomainException('Booking not found.');
            }

            $allocations = $this->normaliseAllocations($data, $booking, $customer, $branch, $currency);
            $allocated = array_sum(array_column($allocations, 'amount_minor'));

            if ($allocated > $amount) {
                throw new DomainException('Allocated amount exceeds the payment amount.');
            }

            $methodRecord = null;
            if (! empty($data['payment_method_id'])) {
                $methodRecord = PaymentMethod::query()
                    ->where('public_id', $data['payment_method_id'])
                    ->where('is_active', true)
                    ->first();
                if (! $methodRecord) {
                    throw new DomainException('Payment method not found or inactive.');
                }
                if ($methodRecord->default_cash_account_id !== null
                    && $methodRecord->default_cash_account_id !== $cashAccount->id) {
                    throw new DomainException('Payment account does not match the selected payment method.');
                }
            }

            $payment = Payment::query()->create([
                'number' => $this->sequences->next('payment', $branch),
                'customer_id' => $customer->id,
                'booking_id' => $booking?->id,
                'branch_id' => $branch->id,
                'method' => $methodRecord?->type ?? ($data['method'] ?? 'cash'),
                'payment_method_id' => $methodRecord?->id,
                'cash_account_id' => $cashAccount->id,
                'amount_minor' => $amount,
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => substr((string) $paidAt, 0, 10),
                'fx_rate_source' => 'manual',
                'unallocated_minor' => $amount - $allocated,
                'status' => 'posted',
                'paid_at' => $paidAt,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            foreach ($allocations as $allocation) {
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'booking_id' => $allocation['booking']->id,
                    'amount_minor' => $allocation['amount_minor'],
                ]);
            }

            $this->postReceipt($payment, $cashAccount, $actorId, $allocations);
            $this->audit->log('payment.recorded', $payment, null, $payment->toApiArray(), $actorId);
            foreach ($allocations as $allocation) {
                $this->commissions->syncBooking($allocation['booking'], $actorId);
            }

            return $payment->refresh()->load('allocations');
        });
    }

    /**
     * Apply TARGET booking amounts (not increments) to a posted receipt.
     * The original receipt stays immutable; only the advance->AR delta is
     * posted as a new balanced journal entry. An exact retry is a no-op.
     */
    public function allocate(Payment $payment, array $allocations, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $actorId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'posted') {
                throw new DomainException('Only posted payments can be allocated.');
            }
            $branch = Branch::query()->findOrFail($payment->branch_id);
            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            $available = $payment->amount_minor - (int) PaymentAllocation::query()
                ->where('payment_id', $payment->id)->sum('amount_minor');
            $seen = [];
            $changes = [];

            foreach ($allocations as $allocation) {
                $bookingId = (string) ($allocation['booking_id'] ?? '');
                $target = (int) ($allocation['amount_minor'] ?? 0);
                if ($target <= 0) {
                    throw new DomainException('Allocation amount must be greater than zero.');
                }
                if (isset($seen[$bookingId])) {
                    throw new DomainException('Duplicate booking within allocation request.');
                }
                $seen[$bookingId] = true;

                $booking = Booking::query()->where('public_id', $bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    throw new DomainException('Booking not found.');
                }
                if ($booking->branch_id !== $branch->id) {
                    throw new DomainException('Cannot allocate to a booking in another branch.');
                }
                if ($booking->customer_id !== $payment->customer_id) {
                    throw new DomainException("Cannot allocate to another customer's booking.");
                }
                if (strtoupper($booking->currency_code) !== strtoupper($payment->currency_code)) {
                    throw new DomainException('Payment currency must match the booking currency.');
                }

                $existing = PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->where('booking_id', $booking->id)->lockForUpdate()->first();
                $previous = $existing ? (int) $existing->amount_minor : 0;
                $delta = $target - $previous;
                if ($delta < 0) {
                    throw new DomainException('To decrease an allocation, unallocate it first.');
                }
                if ($delta === 0) {
                    continue;
                }
                if ($delta > $booking->balanceMinor()) {
                    throw new DomainException("Allocation exceeds the outstanding balance of booking {$booking->number}.");
                }
                if ($delta > $available) {
                    throw new DomainException('Total allocation exceeds the unallocated payment amount.');
                }

                if ($existing) {
                    $existing->update(['amount_minor' => $target]);
                } else {
                    $existing = PaymentAllocation::query()->create([
                        'payment_id' => $payment->id,
                        'booking_id' => $booking->id,
                        'amount_minor' => $target,
                    ]);
                }

                $this->transferAdvance($payment, $branch, $existing, $delta, false, $actorId);
                $available -= $delta;
                $changes[] = ['booking_id' => $booking->public_id, 'delta_minor' => $delta, 'total_minor' => $target];
            }

            $payment->update(['unallocated_minor' => $available]);
            if ($changes !== []) {
                $this->audit->log('payment.allocated', $payment, null, ['allocations' => $changes], $actorId);
                foreach (array_keys($seen) as $bookingPublicId) {
                    $this->commissions->syncBooking(Booking::query()->where('public_id', $bookingPublicId)->value('id'), $actorId);
                }
            }

            return $payment->refresh()->load('allocations');
        });
    }

    public function unallocate(Payment $payment, string $bookingPublicId, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payment, $bookingPublicId, $actorId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'posted') {
                throw new DomainException('Only posted payments can be unallocated.');
            }
            $branch = Branch::query()->findOrFail($payment->branch_id);
            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            $booking = Booking::query()->where('public_id', $bookingPublicId)->first();
            if (! $booking || $booking->branch_id !== $branch->id || $booking->customer_id !== $payment->customer_id) {
                throw new DomainException('Booking not found for this payment.');
            }

            $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)
                ->where('booking_id', $booking->id)->lockForUpdate()->first();
            if (! $allocation) {
                throw new DomainException('No allocation found for this payment and booking.');
            }

            $released = (int) $allocation->amount_minor;
            $this->transferAdvance($payment, $branch, $allocation, $released, true, $actorId);
            $allocation->delete();
            $payment->update([
                'unallocated_minor' => $payment->amount_minor - (int) PaymentAllocation::query()
                    ->where('payment_id', $payment->id)->sum('amount_minor'),
            ]);
            $this->audit->log('payment.unallocated', $payment, null, [
                'booking_id' => $bookingPublicId, 'amount_minor' => $released,
            ], $actorId);
            $this->commissions->syncBooking($booking, $actorId);

            return $payment->refresh()->load('allocations');
        });
    }

    /**
     * The source/event combination identifies each economic allocation step.
     * A deleted allocation is never reused; its release entry remains posted.
     */
    private function transferAdvance(
        Payment $payment,
        Branch $branch,
        PaymentAllocation $allocation,
        int $amountMinor,
        bool $release,
        ?int $actorId,
    ): void {
        $ar = $this->ledger->receivable($payment->customer_id);
        $advance = $this->posting->account((string) $this->settings->get('accounting', 'advance_account_code', '2300'));
        $label = ($release ? 'Unallocate ' : 'Allocate ').'receipt '.$payment->number;
        $lines = $release
            ? [
                PostingLine::debit($ar, $amountMinor, 'customer', $payment->customer_id, $label),
                PostingLine::credit($advance, $amountMinor, 'customer', $payment->customer_id, $label),
            ]
            : [
                PostingLine::debit($advance, $amountMinor, 'customer', $payment->customer_id, $label),
                PostingLine::credit($ar, $amountMinor, 'customer', $payment->customer_id, $label),
            ];

        if ($this->isForeign($payment->currency_code)) {
            // The advance is carried at the receipt rate, the receivable at the
            // booking's rate snapshot; the base difference is realized FX.
            $bookingRate = (string) Booking::query()->findOrFail($allocation->booking_id)->fx_rate;
            $receiptRate = (string) $payment->fx_rate;
            $lines = $this->realizedFx->balance(array_map(
                fn (PostingLine $line) => $line->in(
                    $payment->currency_code,
                    $line->account->id === $ar->id ? $bookingRate : $receiptRate,
                ),
                $lines,
            ), $payment->currency_code);
        }

        $this->posting->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $branch,
            currency: $payment->currency_code,
            lines: $lines,
            memo: $label,
            sourceType: 'payment_allocation',
            sourceId: $allocation->id,
            event: $release ? 'released' : 'allocated:'.$allocation->amount_minor,
            fxRate: (string) $payment->fx_rate,
            fxRateDate: $payment->fx_rate_date,
            fxRateSource: $payment->fx_rate_source,
            actorId: $actorId,
        ));
    }

    /**
     * Cancel a receipt: reverse the receipt entry and every allocation
     * movement exactly once and release the booking allocations. Refused
     * while refunds or active invoice applications depend on the receipt.
     * Cancelling an already cancelled receipt is a no-op.
     */
    public function cancel(Payment $payment, string $reason, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actorId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'cancelled') {
                return $payment->load('allocations');
            }
            if ($payment->status !== 'posted') {
                throw new DomainException('Only posted payments can be cancelled.');
            }
            if ($payment->is_opening) {
                throw new DomainException('This receipt comes from an opening balance batch; reverse the batch instead.');
            }
            $branch = Branch::query()->findOrFail($payment->branch_id);
            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            if (Refund::query()->where('payment_id', $payment->id)->whereIn('status', ['requested', 'approved'])->exists()) {
                throw new DomainException('This receipt has refunds. Resolve or reverse the refunds before cancelling it.');
            }
            if (InvoiceApplication::query()->active()->where('payment_id', $payment->id)->exists()) {
                throw new DomainException('This receipt is applied to invoices. Undo the invoice applications first.');
            }

            $entries = JournalEntry::query()
                ->posted()
                ->whereNull('reversal_of_id')
                ->where('branch_id', $payment->branch_id)
                ->where(function ($query) use ($payment) {
                    $query->where(fn ($q) => $q->where('source_type', 'payment')->where('source_id', $payment->id))
                        ->orWhere(fn ($q) => $q->where('source_type', 'payment_allocation')
                            ->whereIn('memo', ['Allocate receipt '.$payment->number, 'Unallocate receipt '.$payment->number]));
                })
                ->orderBy('id')
                ->get()
                ->filter(fn (JournalEntry $entry) => ! JournalEntry::query()->where('reversal_of_id', $entry->id)->exists());

            foreach ($entries as $entry) {
                $this->posting->reverse($entry, $reason, $actorId);
            }

            $affectedBookings = PaymentAllocation::query()->where('payment_id', $payment->id)->pluck('booking_id');
            PaymentAllocation::query()->where('payment_id', $payment->id)->delete();
            $payment->update(['status' => 'cancelled', 'unallocated_minor' => 0]);
            foreach ($affectedBookings as $affectedBookingId) {
                $this->commissions->syncBooking((int) $affectedBookingId, $actorId);
            }
            $this->audit->log('payment.cancelled', $payment, ['status' => 'posted'], [
                'status' => 'cancelled', 'reason' => $reason, 'reversed_entries' => $entries->count(),
            ], $actorId);

            return $payment->refresh()->load('allocations');
        });
    }

    public function requestRefund(array $data, ?int $actorId = null): Refund
    {
        return DB::transaction(function () use ($data, $actorId) {
            $payment = Payment::query()->where('public_id', $data['payment_id'] ?? '')->first();
            if (! $payment) {
                throw new DomainException('Payment not found.');
            }

            if ($payment->status !== 'posted') {
                throw new DomainException('Refunds can only be requested for posted payments.');
            }
            if ($payment->is_opening) {
                throw new DomainException('Opening credit cannot be refunded in cash; apply it to an invoice.');
            }

            $branch = $payment->branch_id ? Branch::query()->find($payment->branch_id) : null;
            if (! $branch) {
                throw new DomainException('Branch not found.');
            }

            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Refund amount must be greater than zero.');
            }

            $alreadyRefunded = (int) Refund::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', ['requested', 'approved'])
                ->sum('amount_minor');

            if ($alreadyRefunded + $amount > $payment->amount_minor) {
                throw new DomainException('Refund exceeds the refundable amount of this payment.');
            }

            $booking = ! empty($data['booking_id'])
                ? Booking::query()->where('public_id', $data['booking_id'])->first()
                : $payment->booking;

            $refund = Refund::query()->create([
                'number' => $this->sequences->next('refund', $branch),
                'payment_id' => $payment->id,
                'booking_id' => $booking?->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'currency_code' => $payment->currency_code,
                'reason' => $data['reason'] ?? 'Customer refund',
                'status' => 'requested',
                'requested_by' => $actorId,
            ]);

            $this->audit->log('refund.requested', $refund, null, ['amount_minor' => $amount], $actorId);

            // Tell the people who can approve it (W10). Best-effort, like every notification: it never blocks the refund.
            try {
                foreach ($this->notifications->branchUsers($branch, 'refunds.approve') as $approver) {
                    if ($approver->id === $actorId) {
                        continue;
                    }
                    $this->notifications->send($approver->id, 'refund.requested', 'Refund '.$refund->number.' is waiting for approval', null, [
                        'refund_id' => $refund->public_id,
                        'number' => $refund->number,
                    ], $branch->id);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            return $refund;
        });
    }

    public function approveRefund(Refund $refund, int $actorId, ?string $fxRate = null): Refund
    {
        return DB::transaction(function () use ($refund, $actorId, $fxRate) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status !== 'requested') {
                throw new DomainException('Only requested refunds can be approved.');
            }

            // Optional approval rule (off by default, as in Odoo and Zoho): when
            // enabled, the person who requested a refund cannot approve it.
            if ($this->settings->bool('accounting', 'refund_segregation', false)
                && $refund->requested_by !== null
                && (int) $refund->requested_by === $actorId) {
                throw new DomainException('Segregation of duties: the person who requested a refund cannot approve it.');
            }

            $actor = User::query()->findOrFail($actorId);
            $this->branchScope->assertBranch($actor, Branch::query()->findOrFail($refund->branch_id));

            $payment = Payment::query()->findOrFail($refund->payment_id);
            $cashAccount = Account::query()->findOrFail($payment->cash_account_id);

            $ar = $this->ledger->receivable($payment->customer_id);
            $advance = $this->posting->account((string) $this->settings->get('accounting', 'advance_account_code', '2300'));

            $allocatedPortion = $refund->booking_id !== null
                ? min($refund->amount_minor, (int) PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->where('booking_id', $refund->booking_id)
                    ->sum('amount_minor'))
                : 0;
            $advancePortion = $refund->amount_minor - $allocatedPortion;

            $lines = [];
            if ($allocatedPortion > 0) {
                $lines[] = PostingLine::debit($ar, $allocatedPortion, 'customer', $payment->customer_id, 'Refund '.$refund->number);
            }
            if ($advancePortion > 0) {
                $lines[] = PostingLine::debit($advance, $advancePortion, 'customer', $payment->customer_id, 'Refund '.$refund->number);
            }
            $lines[] = PostingLine::credit($cashAccount, $refund->amount_minor, null, null, 'Refund '.$refund->number);

            $refundRate = (string) $payment->fx_rate;
            $refundRateDate = $payment->fx_rate_date;
            $refundRateSource = $payment->fx_rate_source;
            if ($this->isForeign($refund->currency_code)) {
                if ($fxRate !== null) {
                    if (! is_numeric($fxRate) || bccomp($fxRate, '0', 10) <= 0) {
                        throw new DomainException('Refund FX rate must be greater than zero.');
                    }
                    $refundRate = $fxRate;
                    $refundRateDate = now()->toDateString();
                    $refundRateSource = 'manual';
                }
                // Receivable released at the booking rate, advance at the receipt
                // rate, cash leaves at the refund rate; the difference is realized.
                $bookingRate = $allocatedPortion > 0
                    ? (string) Booking::query()->findOrFail($refund->booking_id)->fx_rate
                    : null;
                $lines = $this->realizedFx->balance(array_map(
                    fn (PostingLine $line) => $line->in(
                        $refund->currency_code,
                        match ($line->account->id) {
                            $ar->id => $bookingRate ?? $refundRate,
                            $advance->id => (string) $payment->fx_rate,
                            default => $refundRate,
                        },
                    ),
                    $lines,
                ), $refund->currency_code);
            }

            // The refunded money leaves the receipt: shrink the booking
            // allocation first, then the unallocated advance.
            if ($advancePortion > (int) $payment->unallocated_minor) {
                throw new DomainException('The refund exceeds the unallocated advance of this receipt; unallocate or release invoice applications first.');
            }
            if ($allocatedPortion > 0) {
                $allocation = PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->where('booking_id', $refund->booking_id)
                    ->lockForUpdate()->first();
                $remaining = (int) $allocation->amount_minor - $allocatedPortion;
                $remaining > 0 ? $allocation->update(['amount_minor' => $remaining]) : $allocation->delete();
            }
            $payment->update(['unallocated_minor' => (int) $payment->unallocated_minor - $advancePortion]);

            $refund->update([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: Branch::query()->findOrFail($refund->branch_id),
                currency: $refund->currency_code,
                lines: $lines,
                memo: 'Refund '.$refund->number,
                sourceType: 'refund',
                sourceId: $refund->id,
                event: 'refund',
                fxRate: $refundRate,
                fxRateDate: $refundRateDate,
                fxRateSource: $refundRateSource,
                actorId: $actorId,
            ));

            $this->audit->log('refund.approved', $refund, ['status' => 'requested'], ['status' => 'approved'], $actorId);
            if ($refund->booking_id !== null) {
                $this->commissions->syncBooking((int) $refund->booking_id, $actorId);
            }

            return $refund->refresh();
        });
    }

    /**
     * @return array<int, array{booking: Booking, amount_minor: int}>
     */
    private function normaliseAllocations(array $data, ?Booking $booking, Customer $customer, Branch $branch, string $currency): array
    {
        $requested = $data['allocations'] ?? [];

        if (count($requested) === 0 && $booking) {
            $requested = [[
                'booking_id' => $booking->public_id,
                'amount_minor' => (int) ($data['amount_minor'] ?? 0),
            ]];
        }

        $result = [];
        foreach ($requested as $allocation) {
            $target = ! empty($allocation['booking_id'])
                ? Booking::query()->where('public_id', $allocation['booking_id'])->first()
                : $booking;

            if (! $target) {
                throw new DomainException('Allocation booking not found.');
            }

            if ($target->branch_id !== $branch->id) {
                throw new DomainException('Cannot allocate a payment to a booking in another branch.');
            }

            if ($target->customer_id !== $customer->id) {
                throw new DomainException('Cannot allocate a payment to another customer\'s booking.');
            }

            if (strtoupper($target->currency_code) !== $currency) {
                throw new DomainException('Payment currency must match the booking currency.');
            }

            $amount = (int) ($allocation['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Allocation amount must be greater than zero.');
            }

            if ($amount > $target->balanceMinor()) {
                throw new DomainException("Allocation exceeds the outstanding balance of booking {$target->number}.");
            }

            $result[] = ['booking' => $target, 'amount_minor' => $amount];
        }

        return $result;
    }

    /**
     * @param  array<int, array{booking: Booking, amount_minor: int}>  $allocations
     */
    private function postReceipt(Payment $payment, Account $cashAccount, ?int $actorId, array $allocations = []): void
    {
        $ar = $this->ledger->receivable($payment->customer_id);
        $advance = $this->posting->account((string) $this->settings->get('accounting', 'advance_account_code', '2300'));

        $allocated = $payment->amount_minor - $payment->unallocated_minor;

        $lines = [PostingLine::debit($cashAccount, $payment->amount_minor, null, null, 'Receipt '.$payment->number)];
        if ($allocated > 0) {
            $lines[] = PostingLine::credit($ar, $allocated, 'customer', $payment->customer_id, 'Receipt '.$payment->number);
        }
        if ($payment->unallocated_minor > 0) {
            $lines[] = PostingLine::credit($advance, $payment->unallocated_minor, 'customer', $payment->customer_id, 'Advance '.$payment->number);
        }

        if ($this->isForeign($payment->currency_code)) {
            // Cash and advance at the receipt rate; each allocated portion
            // clears its booking receivable at that booking's rate.
            $receiptRate = (string) $payment->fx_rate;
            $lines = [PostingLine::debit($cashAccount, $payment->amount_minor, null, null, 'Receipt '.$payment->number)
                ->in($payment->currency_code, $receiptRate)];
            foreach ($allocations as $allocation) {
                $lines[] = PostingLine::credit($ar, $allocation['amount_minor'], 'customer', $payment->customer_id, 'Receipt '.$payment->number)
                    ->in($payment->currency_code, (string) $allocation['booking']->fx_rate);
            }
            if ($payment->unallocated_minor > 0) {
                $lines[] = PostingLine::credit($advance, $payment->unallocated_minor, 'customer', $payment->customer_id, 'Advance '.$payment->number)
                    ->in($payment->currency_code, $receiptRate);
            }
            $lines = $this->realizedFx->balance($lines, $payment->currency_code);
        }

        $this->posting->post(PostingRequest::make(
            entryDate: $payment->paid_at->toDateString(),
            branch: Branch::query()->findOrFail($payment->branch_id),
            currency: $payment->currency_code,
            lines: $lines,
            memo: 'Receipt '.$payment->number,
            sourceType: 'payment',
            sourceId: $payment->id,
            event: 'receipt',
            fxRate: (string) $payment->fx_rate,
            fxRateDate: $payment->fx_rate_date,
            fxRateSource: $payment->fx_rate_source,
            actorId: $actorId,
        ));
    }

    private function isForeign(string $currency): bool
    {
        return strtoupper($currency) !== $this->settings->baseCurrency();
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
