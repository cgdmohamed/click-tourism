<?php

namespace App\Modules\Commissions\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\Commission;
use App\Modules\Commissions\Models\CommissionPayment;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Treasury\Models\CashAccount;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Commission accrual lifecycle (DEC-003/004/013/021, closed 2026-11-11):
 *
 *   booking fully collected -> accrued (rule snapshot, no journal)
 *   accrued -> approved (maker-checker) -> posted (Dr commission expense / Cr payable)
 *   posted -> paid (Dr payable / Cr cash or bank, possibly in parts)
 *   cancellation, refund or unallocation -> cancelled (never posted) or reversed (posted)
 *
 * Commissions are base-currency obligations. There is no commission tax.
 * The ledger is only touched through PostingService, which refuses to post
 * until the accounting policy is approved; commission_auto_post (default
 * false) only controls whether approval posts by itself.
 */
class CommissionService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly AuditService $audit,
        private readonly PartyLedgerService $ledger,
        private readonly CommissionRuleService $rules,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * Re-evaluate a booking after its collection state changed (receipt,
     * allocation, unallocation, receipt cancellation, refund, booking
     * cancellation). Never lets a commission problem break the cash or booking
     * operation that triggered it: failures are reported and the commission is
     * left for manual attention.
     */
    public function syncBooking(Booking|int $booking, ?int $actorId = null): void
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;

        try {
            DB::transaction(fn () => $this->sync($id, $actorId));
        } catch (DomainException $e) {
            $this->audit->log('commission.sync_failed', null, null, ['booking_id' => $id, 'error' => $e->getMessage()], $actorId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function sync(int $bookingId, ?int $actorId): void
    {
        $booking = Booking::query()->lockForUpdate()->find($bookingId);
        if (! $booking) {
            return;
        }

        if ($booking->status === 'cancelled') {
            $this->reverseLive($booking, 'Booking cancelled', $actorId);

            return;
        }

        if ($this->fullyCollected($booking)) {
            $this->accrue($booking, $actorId);

            return;
        }

        $this->reverseLive($booking, 'Booking is no longer fully collected (refund, unallocation or receipt cancelled)', $actorId);
    }

    /** DEC-004: the trigger is "fully collected" - allocated receipts cover the whole booking total. */
    public function fullyCollected(Booking $booking): bool
    {
        return in_array($booking->status, ['confirmed', 'in_progress', 'completed', 'closed'], true)
            && $booking->total_sell_minor > 0
            && $booking->paidMinor() >= $booking->total_sell_minor;
    }

    /** @return list<Commission> Commissions created by this call. */
    private function accrue(Booking $booking, ?int $actorId): array
    {
        $date = $this->collectionDate($booking);
        $created = [];

        foreach ($this->rules->resolve($booking, $date) as $winner) {
            $rule = $winner['rule'];
            $key = Commission::activeKey($booking->id, $winner['beneficiary_type'], $winner['beneficiary_id']);
            if (Commission::query()->where('active_key', $key)->exists()) {
                continue; // already accrued (or rejected): no duplicate accrual
            }

            $result = $this->calculator->forBooking($rule, $booking);
            if ($result['amount_minor'] <= 0) {
                continue;
            }

            $commission = Commission::query()->create([
                'number' => $this->sequences->next('commission', $booking->branch, Carbon::parse($date)),
                'booking_id' => $booking->id,
                'branch_id' => $booking->branch_id,
                'commission_rule_id' => $rule->id,
                'rule_code' => $rule->code,
                'rule_version' => $rule->version,
                'beneficiary_type' => $winner['beneficiary_type'],
                'beneficiary_id' => $winner['beneficiary_id'],
                'basis' => $rule->basis,
                'rate_bp' => $rule->rate_bp,
                'basis_amount_minor' => $result['basis_minor'],
                'calculated_minor' => $result['amount_minor'],
                'amount_minor' => $result['amount_minor'],
                'currency_code' => $this->settings->baseCurrency(),
                'status' => 'accrued',
                'active_key' => $key,
                'accrued_on' => $date,
                'created_by' => $actorId,
            ]);
            $this->audit->log('commission.accrued', $commission, null, [
                'number' => $commission->number,
                'booking' => $booking->number,
                'rule' => $rule->code.' v'.$rule->version,
                'amount_minor' => $commission->amount_minor,
            ], $actorId);
            $created[] = $commission;
        }

        return $created;
    }

    private function collectionDate(Booking $booking): string
    {
        $paymentIds = PaymentAllocation::query()->where('booking_id', $booking->id)->pluck('payment_id');
        $last = $paymentIds->isEmpty() ? null : Payment::query()->whereIn('id', $paymentIds)->max('paid_at');

        return $last !== null ? Carbon::parse($last)->toDateString() : now()->toDateString();
    }

    /**
     * Reverse every live commission of a booking. Unposted accruals are simply
     * cancelled; posted ones are reversed through the ledger. When the ledger
     * refuses (closed period, policy revoked) the commission is flagged
     * `needs_reversal` for an accountant instead of blocking the caller.
     */
    private function reverseLive(Booking $booking, string $reason, ?int $actorId): void
    {
        Commission::query()->where('booking_id', $booking->id)
            ->whereIn('status', ['accrued', 'approved', 'posted', 'paid', 'rejected'])
            ->lockForUpdate()->get()
            ->each(function (Commission $commission) use ($reason, $actorId) {
                try {
                    DB::transaction(fn () => $this->doReverse($commission, $reason, null, $actorId));
                } catch (DomainException $e) {
                    $commission->update(['needs_reversal' => true]);
                    $this->audit->log('commission.reversal_blocked', $commission, null, ['reason' => $reason, 'error' => $e->getMessage()], $actorId);
                }
            });
    }

    /** Manual reversal (accountant). A posted commission is reversed in the ledger by the actor. */
    public function reverse(Commission $commission, string $reason, ?int $actorId): Commission
    {
        return DB::transaction(function () use ($commission, $reason, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            if (! in_array($commission->status, ['accrued', 'approved', 'posted', 'paid', 'rejected'], true)) {
                throw new DomainException('This commission is already reversed or cancelled.');
            }

            $this->doReverse($commission, $reason, $actorId, $actorId);

            return $this->fresh($commission);
        });
    }

    private function doReverse(Commission $commission, string $reason, ?int $ledgerActorId, ?int $auditActorId): void
    {
        $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
        $before = $commission->status;

        if (in_array($before, ['posted', 'paid'], true)) {
            $reversal = $this->posting->reverse($commission->journalEntry, $reason, $ledgerActorId);
            $commission->update([
                'status' => 'reversed',
                'reversal_journal_entry_id' => $reversal->id,
            ]);
        } elseif (in_array($before, ['accrued', 'approved', 'rejected'], true)) {
            $commission->update(['status' => 'cancelled']);
        } else {
            return;
        }

        $commission->update(['active_key' => null, 'reversed_at' => now(), 'reversal_reason' => $reason, 'needs_reversal' => false]);
        $this->audit->log('commission.reversed', $commission, ['status' => $before], [
            'status' => $commission->status,
            'reason' => $reason,
            // A payout made before the reversal stays booked: the beneficiary now owes it back.
            'paid_minor' => $commission->paid_minor,
        ], $auditActorId);
    }

    /**
     * Propose a different amount than the rule calculated. The override only
     * takes effect once someone else approves the commission (DEC-017 style
     * maker-checker, setting commissions.segregation).
     */
    public function override(Commission $commission, int $amountMinor, string $reason, ?int $actorId): Commission
    {
        return DB::transaction(function () use ($commission, $amountMinor, $reason, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            if ($commission->status !== 'accrued') {
                throw new DomainException('Only commissions awaiting approval can be overridden.');
            }
            if ($amountMinor < 0) {
                throw new DomainException('The override amount cannot be negative.');
            }

            $before = ['amount_minor' => $commission->amount_minor, 'override_minor' => $commission->override_minor];
            $commission->update([
                'override_minor' => $amountMinor,
                'override_reason' => $reason,
                'override_by' => $actorId,
                'amount_minor' => $amountMinor,
            ]);
            $this->audit->log('commission.overridden', $commission, $before, ['override_minor' => $amountMinor, 'reason' => $reason], $actorId);

            return $this->fresh($commission);
        });
    }

    public function approve(Commission $commission, ?int $actorId): Commission
    {
        return DB::transaction(function () use ($commission, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            if ($commission->status !== 'accrued') {
                throw new DomainException('Only accrued commissions can be approved.');
            }

            if ($this->settings->bool('commissions', 'segregation', true) && $actorId !== null) {
                if ($commission->override_by !== null && $commission->override_by === $actorId) {
                    throw new DomainException('The person who overrode a commission amount cannot approve it.');
                }
                $beneficiaryUser = $commission->beneficiary_type === 'employee'
                    ? Employee::query()->whereKey($commission->beneficiary_id)->value('user_id') : null;
                if ($beneficiaryUser !== null && (int) $beneficiaryUser === $actorId) {
                    throw new DomainException('A beneficiary cannot approve their own commission.');
                }
            }

            if ($commission->amount_minor <= 0) {
                throw new DomainException('A commission of zero cannot be approved; reject it instead.');
            }

            $commission->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log('commission.approved', $commission, ['status' => 'accrued'], [
                'status' => 'approved', 'amount_minor' => $commission->amount_minor,
            ], $actorId);

            if ($this->settings->bool('accounting', 'commission_auto_post', false)
                && $this->settings->bool('accounting', 'accounting_policy_approved', false)) {
                try {
                    DB::transaction(fn () => $this->doPost($commission, $actorId));
                } catch (DomainException $e) {
                    $this->audit->log('commission.auto_post_failed', $commission, null, ['error' => $e->getMessage()], $actorId);
                }
            }

            return $this->fresh($commission);
        });
    }

    public function reject(Commission $commission, string $reason, ?int $actorId): Commission
    {
        return DB::transaction(function () use ($commission, $reason, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            if ($commission->status !== 'accrued') {
                throw new DomainException('Only accrued commissions can be rejected.');
            }

            // A rejected commission keeps its slot so the same booking is not accrued again.
            $commission->update(['status' => 'rejected', 'rejection_reason' => $reason]);
            $this->audit->log('commission.rejected', $commission, ['status' => 'accrued'], ['status' => 'rejected', 'reason' => $reason], $actorId);

            return $this->fresh($commission);
        });
    }

    public function post(Commission $commission, ?int $actorId): Commission
    {
        return DB::transaction(function () use ($commission, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            $this->doPost($commission, $actorId);

            return $this->fresh($commission);
        });
    }

    private function doPost(Commission $commission, ?int $actorId): void
    {
        if ($commission->status !== 'approved') {
            throw new DomainException('Only approved commissions can be posted.');
        }

        $booking = $commission->booking;
        [$payable, $partyType, $partyId] = $this->payable($commission);

        $entry = $this->posting->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $commission->branch,
            currency: $commission->currency_code,
            lines: [
                PostingLine::debit($this->expenseAccount(), $commission->amount_minor, null, null, 'Commission '.$commission->number),
                PostingLine::credit($payable, $commission->amount_minor, $partyType, $partyId, 'Commission payable '.$commission->number),
            ],
            memo: 'Commission '.$commission->number.' - booking '.$booking->number,
            sourceType: 'commission',
            sourceId: $commission->id,
            event: 'accrual',
            actorId: $actorId,
        ));

        $commission->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);
        $this->audit->log('commission.posted', $commission, ['status' => 'approved'], ['status' => 'posted', 'journal' => $entry->number], $actorId);
    }

    /**
     * Settle (part of) a posted commission: Dr payable / Cr cash or bank.
     *
     * @param  array<string, mixed>  $data  cash_account_id, amount_minor?, paid_on?, reference?
     */
    public function pay(Commission $commission, array $data, ?int $actorId): CommissionPayment
    {
        return DB::transaction(function () use ($commission, $data, $actorId) {
            $commission = Commission::query()->lockForUpdate()->findOrFail($commission->id);
            if ($commission->status !== 'posted') {
                throw new DomainException('Only posted commissions can be paid.');
            }
            if ($commission->needs_reversal) {
                throw new DomainException('This commission must be reversed; it cannot be paid.');
            }

            $outstanding = $commission->amount_minor - $commission->paid_minor;
            $amount = isset($data['amount_minor']) ? (int) $data['amount_minor'] : $outstanding;
            if ($amount <= 0) {
                throw new DomainException('The payment amount must be greater than zero.');
            }
            if ($amount > $outstanding) {
                throw new DomainException('The payment exceeds the outstanding commission payable.');
            }

            $cash = CashAccount::query()->with('account')->where('public_id', (string) ($data['cash_account_id'] ?? ''))->first();
            if (! $cash || ! $cash->is_active) {
                throw new DomainException('Choose an active cash or bank account.');
            }
            if ($cash->branch_id !== null && $cash->branch_id !== $commission->branch_id) {
                throw new DomainException('The cash account belongs to another branch.');
            }
            if (strtoupper($cash->currency_code) !== $commission->currency_code) {
                throw new DomainException("Commissions are paid in {$commission->currency_code}; choose a {$commission->currency_code} cash or bank account.");
            }

            $paidOn = Carbon::parse((string) ($data['paid_on'] ?? now()->toDateString()))->toDateString();
            $payment = CommissionPayment::query()->create([
                'number' => $this->sequences->next('commission_payment', $commission->branch, Carbon::parse($paidOn)),
                'commission_id' => $commission->id,
                'branch_id' => $commission->branch_id,
                'cash_account_id' => $cash->id,
                'amount_minor' => $amount,
                'currency_code' => $commission->currency_code,
                'paid_on' => $paidOn,
                'reference' => $data['reference'] ?? null,
                'status' => 'posted',
                'created_by' => $actorId,
            ]);

            [$payable, $partyType, $partyId] = $this->payable($commission);
            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $paidOn,
                branch: $commission->branch,
                currency: $commission->currency_code,
                lines: [
                    PostingLine::debit($payable, $amount, $partyType, $partyId, 'Commission payout '.$payment->number),
                    PostingLine::credit($cash->account, $amount, null, null, 'Commission payout '.$payment->number),
                ],
                memo: 'Commission payout '.$payment->number.' - '.$commission->number,
                sourceType: 'commission_payment',
                sourceId: $payment->id,
                event: 'payment',
                actorId: $actorId,
            ));

            $payment->update(['journal_entry_id' => $entry->id]);
            $paid = $commission->paid_minor + $amount;
            $commission->update(['paid_minor' => $paid, 'status' => $paid >= $commission->amount_minor ? 'paid' : 'posted']);
            $this->audit->log('commission.paid', $commission, null, [
                'payment' => $payment->number, 'amount_minor' => $amount, 'paid_minor' => $paid, 'journal' => $entry->number,
            ], $actorId);

            return $payment->refresh()->load('commission', 'cashAccount', 'journalEntry');
        });
    }

    /**
     * Payable subaccount of the beneficiary (ADR-0015): agents are suppliers,
     * employees get a payable subaccount, a branch uses the payables control account.
     *
     * @return array{0: Account, 1: string, 2: int}
     */
    private function payable(Commission $commission): array
    {
        return match ($commission->beneficiary_type) {
            'agent' => [$this->ledger->payable($commission->beneficiary_id), 'supplier', $commission->beneficiary_id],
            'employee' => [$this->ledger->account('employee', $commission->beneficiary_id, PartyAccount::ROLE_PAYABLE), 'employee', $commission->beneficiary_id],
            'branch' => [$this->posting->account((string) $this->settings->get('accounting', 'ap_account_code', '2100')), 'branch', $commission->beneficiary_id],
            default => throw new DomainException('Unsupported commission beneficiary.'),
        };
    }

    private function expenseAccount(): Account
    {
        return $this->posting->account((string) $this->settings->get('accounting', 'commission_expense_account_code', '6700'));
    }

    private function fresh(Commission $commission): Commission
    {
        return $commission->refresh()->load('booking', 'branch', 'rule', 'journalEntry', 'reversalJournalEntry');
    }

    /**
     * Beneficiary statement: accruals, reversals and payouts in a window,
     * with the amount currently payable. Limited to the branches the caller may see.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function statement(string $type, int $beneficiaryId, string $from, string $to, array $branchIds): array
    {
        $commissions = Commission::query()->with('booking', 'branch', 'rule')
            ->where('beneficiary_type', $type)->where('beneficiary_id', $beneficiaryId)
            ->whereIn('branch_id', $branchIds)
            ->whereDate('accrued_on', '>=', $from)->whereDate('accrued_on', '<=', $to)
            ->orderBy('accrued_on')->orderBy('id')->get();

        $payments = CommissionPayment::query()->with('commission')
            ->whereIn('commission_id', $commissions->pluck('id'))
            ->orderBy('paid_on')->orderBy('id')->get();

        $sum = fn (array $statuses, string $column = 'amount_minor') => (int) $commissions->whereIn('status', $statuses)->sum($column);

        $lines = [];
        foreach ($commissions as $commission) {
            $lines[] = [
                'date' => $commission->accrued_on->toDateString(),
                'type' => 'commission',
                'commission' => $commission->toApiArray(),
                'amount_minor' => $commission->amount_minor,
            ];
        }
        foreach ($payments as $payment) {
            $lines[] = [
                'date' => $payment->paid_on->toDateString(),
                'type' => 'payment',
                'commission' => $payment->commission->toApiArray(),
                'amount_minor' => -$payment->amount_minor,
            ];
        }
        usort($lines, fn ($a, $b) => $a['date'] <=> $b['date']);

        return [
            'beneficiary_type' => $type,
            'beneficiary' => CommissionRule::describeBeneficiary($type, $beneficiaryId),
            'from' => $from,
            'to' => $to,
            'currency' => $this->settings->baseCurrency(),
            'summary' => [
                'pending_approval_minor' => $sum(['accrued']),
                'approved_unposted_minor' => $sum(['approved']),
                'payable_minor' => (int) $commissions->whereIn('status', ['posted'])->sum(fn (Commission $c) => $c->amount_minor - $c->paid_minor),
                'paid_minor' => (int) $commissions->whereIn('status', ['posted', 'paid', 'reversed'])->sum('paid_minor'),
                'reversed_minor' => $sum(['reversed']),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * One row per beneficiary with totals, for the statements overview.
     *
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    public function beneficiaries(array $branchIds, ?string $type = null): array
    {
        $rows = Commission::query()->whereIn('branch_id', $branchIds)
            ->when($type, fn ($q) => $q->where('beneficiary_type', $type))
            ->get()->groupBy(fn (Commission $c) => $c->beneficiary_type.':'.$c->beneficiary_id);

        return $rows->map(function ($items) {
            /** @var Commission $first */
            $first = $items->first();

            return [
                'beneficiary_type' => $first->beneficiary_type,
                'beneficiary' => CommissionRule::describeBeneficiary($first->beneficiary_type, $first->beneficiary_id),
                'pending_approval_minor' => (int) $items->where('status', 'accrued')->sum('amount_minor'),
                'approved_unposted_minor' => (int) $items->where('status', 'approved')->sum('amount_minor'),
                'payable_minor' => (int) $items->where('status', 'posted')->sum(fn (Commission $c) => $c->amount_minor - $c->paid_minor),
                'paid_minor' => (int) $items->whereIn('status', ['posted', 'paid', 'reversed'])->sum('paid_minor'),
            ];
        })->values()->all();
    }
}
