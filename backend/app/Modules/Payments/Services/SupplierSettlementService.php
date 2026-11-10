<?php

namespace App\Modules\Payments\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\RealizedFxService;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Bookings\Models\BookingItem;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Payments\Models\SupplierPaymentApplication;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Supplier obligations and payments (TE-031, AC-PAY-008/009).
 *
 * Obligations are created explicitly (manually or by an import); automatic
 * creation from booking costs is blocked on DEC-002 and is deliberately not
 * guessed here. A payment either settles one obligation or is booked as a
 * supplier advance that can be applied later. Every movement posts exactly
 * one balanced journal entry through PostingService (ADR-0004).
 */
class SupplierSettlementService
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
    ) {}

    public function createObligation(array $data, ?int $actorId = null): SupplierObligation
    {
        return DB::transaction(function () use ($data, $actorId) {
            $branch = $this->branch($data['branch_id'] ?? null);
            $this->assertActor($actorId, $branch);

            $supplier = $this->supplier($data['supplier_id'] ?? null);

            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Obligation amount must be greater than zero.');
            }

            $currency = strtoupper((string) ($data['currency'] ?? $supplier->settlement_currency ?? $this->settings->baseCurrency()));

            $item = $this->bookingItem($data['booking_item_id'] ?? null, $branch, $supplier);

            $reference = trim((string) ($data['invoice_reference'] ?? ''));
            if ($reference !== '') {
                $duplicate = SupplierObligation::query()
                    ->where('supplier_id', $supplier->id)
                    ->where('invoice_reference', $reference)
                    ->exists();

                if ($duplicate) {
                    throw new DomainException('An obligation with this invoice reference already exists for the supplier.');
                }
            }

            $invoice = ! empty($data['invoice_id'])
                ? $this->eligibleInvoice($data['invoice_id'], $supplier, $branch, $currency, $amount)
                : null;

            $obligation = SupplierObligation::query()->create([
                'supplier_id' => $supplier->id,
                'invoice_id' => $invoice?->id,
                'booking_item_id' => $item?->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'currency_code' => $currency,
                'invoice_reference' => $reference !== '' ? $reference : null,
                'status' => 'open',
            ]);

            $this->audit->log('supplier_obligation.created', $obligation, null, $obligation->toApiArray(), $actorId);

            return $obligation->refresh()->load('supplier', 'branch', 'bookingItem');
        });
    }

    /**
     * Link an operational obligation to an already issued supplier invoice.
     * The invoice has already recognized AP; linking never creates a second
     * journal entry. Existing applied payments must be reconciled manually.
     */
    public function recognizeObligation(SupplierObligation $obligation, string $invoicePublicId, ?int $actorId): SupplierObligation
    {
        return DB::transaction(function () use ($obligation, $invoicePublicId, $actorId) {
            $obligation = SupplierObligation::query()->lockForUpdate()->findOrFail($obligation->id);
            $branch = Branch::query()->findOrFail($obligation->branch_id);
            $this->assertActor($actorId, $branch);
            if ($obligation->invoice_id !== null) {
                throw new DomainException('Supplier obligation is already linked to an invoice.');
            }
            if ($obligation->applications()->exists()) {
                throw new DomainException('Previously settled obligations require accountant reconciliation before invoice recognition.');
            }

            $supplier = Supplier::query()->findOrFail($obligation->supplier_id);
            $invoice = $this->eligibleInvoice($invoicePublicId, $supplier, $branch, $obligation->currency_code, (int) $obligation->amount_minor);
            $obligation->update(['invoice_id' => $invoice->id]);
            $this->audit->log('supplier_obligation.recognized', $obligation, null, [
                'invoice_id' => $invoice->public_id, 'journal_entry_id' => $invoice->journal_entry_id,
            ], $actorId);

            return $obligation->refresh()->load('invoice', 'supplier', 'branch');
        });
    }

    private function eligibleInvoice(string $publicId, Supplier $supplier, Branch $branch, string $currency, int $amount): Invoice
    {
        $invoice = Invoice::query()->where('public_id', $publicId)->lockForUpdate()->first();
        if ($invoice === null || $invoice->type !== Invoice::TYPE_SUPPLIER_INVOICE
            || $invoice->status !== Invoice::STATUS_ISSUED || $invoice->journal_entry_id === null
            || $invoice->party_type !== 'supplier' || $invoice->party_id !== $supplier->id
            || $invoice->branch_id !== $branch->id || strtoupper($invoice->currency_code) !== strtoupper($currency)
            || (int) $invoice->total_minor !== $amount) {
            throw new DomainException('Select an issued supplier invoice matching supplier, branch, currency and full obligation amount.');
        }
        if ((int) $invoice->outstanding_minor !== $amount) {
            throw new DomainException('The invoice must be fully outstanding before linking to an operational obligation.');
        }
        if (SupplierObligation::query()->where('invoice_id', $invoice->id)->exists()) {
            throw new DomainException('This invoice is already linked to another supplier obligation.');
        }

        return $invoice;
    }

    private function recognizedInvoice(SupplierObligation $obligation): Invoice
    {
        if ($obligation->invoice_id === null) {
            throw new DomainException('This obligation is operational only. Issue and link its supplier invoice before settling AP; use an unallocated supplier advance for prepayments.');
        }
        $invoice = Invoice::query()->whereKey($obligation->invoice_id)->lockForUpdate()->first();
        if ($invoice === null || $invoice->status !== Invoice::STATUS_ISSUED || $invoice->journal_entry_id === null) {
            throw new DomainException('A posted supplier invoice is required to settle this obligation.');
        }

        return $invoice;
    }

    public function recordPayment(array $data, ?int $actorId = null, ?string $idempotencyKey = null): SupplierPayment
    {
        return DB::transaction(function () use ($data, $actorId, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $existing = SupplierPayment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->when($actorId !== null, fn ($query) => $query->where('created_by', $actorId))
                    ->first();

                if ($existing) {
                    return $existing->load('supplier', 'obligation', 'branch', 'applications');
                }
            }

            $branch = $this->branch($data['branch_id'] ?? null);
            $this->assertActor($actorId, $branch);

            $supplier = $this->supplier($data['supplier_id'] ?? null);
            $cashAccount = $this->cashAccount($data['cash_account_id'] ?? null);

            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Supplier payment amount must be greater than zero.');
            }

            $currency = strtoupper((string) ($data['currency'] ?? $supplier->settlement_currency ?? $this->settings->baseCurrency()));
            $paidAt = $data['paid_at'] ?? now()->toIso8601String();
            $paidDate = substr((string) $paidAt, 0, 10);

            $fxRate = (string) ($data['fx_rate'] ?? $this->fx->rateFor($currency, $paidDate));
            $fxSource = ! empty($data['fx_rate']) ? 'manual' : 'reference';

            $obligation = ! empty($data['supplier_obligation_id'])
                ? $this->obligation($data['supplier_obligation_id'], $supplier, $branch, $currency)
                : null;

            $recognizedInvoice = $obligation !== null ? $this->recognizedInvoice($obligation) : null;
            if ($obligation !== null && $amount > $obligation->balanceMinor()) {
                throw new DomainException("Payment exceeds the outstanding balance of obligation {$obligation->public_id}.");
            }

            if ($recognizedInvoice !== null && $amount > (int) $recognizedInvoice->outstanding_minor) {
                throw new DomainException('Payment exceeds the issued supplier invoice outstanding amount.');
            }

            $payment = SupplierPayment::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_obligation_id' => $obligation?->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'currency_code' => $currency,
                'fx_rate' => $fxRate,
                'fx_rate_date' => $paidDate,
                'fx_rate_source' => $fxSource,
                'cash_account_id' => $cashAccount->id,
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
                'paid_at' => $paidAt,
            ]);

            if ($obligation !== null) {
                $this->applyToObligation($payment, $obligation, $amount);
                $this->postSettlement($payment, $cashAccount, $amount, $recognizedInvoice, $actorId);
                $recognizedInvoice->update(['outstanding_minor' => (int) $recognizedInvoice->outstanding_minor - $amount]);
            } else {
                $this->postAdvance($payment, $cashAccount, $amount, $actorId);
            }

            $this->audit->log('supplier_payment.recorded', $payment, null, $payment->toApiArray(), $actorId);

            return $payment->refresh()->load('supplier', 'obligation', 'branch', 'applications');
        });
    }

    /**
     * Apply part or all of an unapplied supplier payment (advance) to an open
     * obligation. Posts the reclassification DR AP / CR supplier advances.
     */
    public function applyAdvance(SupplierPayment $payment, array $data, ?int $actorId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $data, $actorId) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $branch = Branch::query()->findOrFail($payment->branch_id);
            $this->assertActor($actorId, $branch);

            $supplier = $this->supplier($payment->supplier?->public_id ?? null);
            $obligation = $this->obligation(
                $data['supplier_obligation_id'] ?? null,
                $supplier,
                $branch,
                strtoupper((string) $payment->currency_code),
            );

            $invoice = $this->recognizedInvoice($obligation);
            $amount = (int) ($data['amount_minor'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException('Application amount must be greater than zero.');
            }

            $available = $payment->unallocatedMinor();
            if ($available <= 0) {
                throw new DomainException('This supplier payment has no unapplied amount.');
            }

            if ($amount > $available) {
                throw new DomainException('Application exceeds the unapplied amount of this payment.');
            }

            if ($amount > $obligation->balanceMinor() || $amount > (int) $invoice->outstanding_minor) {
                throw new DomainException("Application exceeds the outstanding balance of obligation {$obligation->public_id} or its issued invoice.");
            }

            $application = $this->applyToObligation($payment, $obligation, $amount);

            $advanceAccount = $this->posting->account($this->advanceAccountCode());
            $ap = $this->ledger->payable($payment->supplier_id);

            $applicationLines = $this->realizedFx->balance([
                PostingLine::debit($ap, $amount, 'supplier', $payment->supplier_id, 'Apply advance '.$payment->public_id)
                    ->in($payment->currency_code, (string) $invoice->fx_rate),
                PostingLine::credit($advanceAccount, $amount, 'supplier', $payment->supplier_id, 'Apply advance '.$payment->public_id)
                    ->in($payment->currency_code, (string) $payment->fx_rate),
            ], $payment->currency_code);
            $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: $branch,
                currency: $payment->currency_code,
                lines: $applicationLines,
                memo: 'Apply supplier advance '.$payment->public_id,
                sourceType: 'supplier_payment_application',
                sourceId: $application->id,
                event: 'apply',
                fxRate: (string) $payment->fx_rate,
                fxRateDate: $payment->fx_rate_date,
                fxRateSource: $payment->fx_rate_source,
                actorId: $actorId,
            ));

            $invoice->update(['outstanding_minor' => (int) $invoice->outstanding_minor - $amount]);
            $this->audit->log('supplier_payment.applied', $payment, null, [
                'obligation' => $obligation->public_id,
                'amount_minor' => $amount,
            ], $actorId);

            return $payment->refresh()->load('supplier', 'obligation', 'branch', 'applications');
        });
    }

    private function applyToObligation(SupplierPayment $payment, SupplierObligation $obligation, int $amount): SupplierPaymentApplication
    {
        $application = SupplierPaymentApplication::query()->create([
            'supplier_payment_id' => $payment->id,
            'supplier_obligation_id' => $obligation->id,
            'amount_minor' => $amount,
        ]);

        $obligation->refreshStatus();

        return $application;
    }

    /**
     * Unapply a supplier payment from an obligation, returning the amount to
     * the unapplied advance balance. Posts a reversing entry (CR AP / DR
     * supplier advances) so the ledger stays balanced.
     */
    public function unapply(SupplierPayment $payment, string $applicationPublicId, ?int $actorId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $applicationPublicId, $actorId) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $branch = Branch::query()->findOrFail($payment->branch_id);
            $this->assertActor($actorId, $branch);

            $application = SupplierPaymentApplication::query()
                ->where('supplier_payment_id', $payment->id)
                ->where('public_id', $applicationPublicId)
                ->first();

            if (! $application) {
                throw new DomainException('Application not found for this payment.');
            }

            $obligation = $application->obligation;
            $invoice = $this->recognizedInvoice($obligation);
            $amount = (int) $application->amount_minor;

            $application->delete();
            $obligation->refreshStatus();

            $advanceAccount = $this->posting->account($this->advanceAccountCode());
            $ap = $this->ledger->payable($payment->supplier_id);

            $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: $branch,
                currency: $payment->currency_code,
                lines: $this->realizedFx->balance([
                    PostingLine::credit($ap, $amount, 'supplier', $payment->supplier_id, 'Unapply advance '.$payment->public_id)
                        ->in($payment->currency_code, (string) $invoice->fx_rate),
                    PostingLine::debit($advanceAccount, $amount, 'supplier', $payment->supplier_id, 'Unapply advance '.$payment->public_id)
                        ->in($payment->currency_code, (string) $payment->fx_rate),
                ], $payment->currency_code),
                memo: 'Unapply supplier advance '.$payment->public_id,
                sourceType: 'supplier_payment_application',
                sourceId: $application->id,
                event: 'unapply',
                fxRate: (string) $payment->fx_rate,
                fxRateDate: $payment->fx_rate_date,
                fxRateSource: $payment->fx_rate_source,
                actorId: $actorId,
            ));

            $invoice->update(['outstanding_minor' => (int) $invoice->outstanding_minor + $amount]);
            $this->audit->log('supplier_payment.unapplied', $payment, null, [
                'obligation' => $obligation->public_id,
                'amount_minor' => $amount,
            ], $actorId);

            return $payment->refresh()->load('supplier', 'obligation', 'branch', 'applications');
        });
    }

    private function postSettlement(SupplierPayment $payment, Account $cashAccount, int $amount, Invoice $invoice, ?int $actorId): void
    {
        $ap = $this->ledger->payable($payment->supplier_id);
        $lines = $this->realizedFx->balance([
            PostingLine::debit($ap, $amount, 'supplier', $payment->supplier_id, 'Supplier payment '.$payment->public_id)
                ->in($payment->currency_code, (string) $invoice->fx_rate),
            PostingLine::credit($cashAccount, $amount, null, null, 'Supplier payment '.$payment->public_id)
                ->in($payment->currency_code, (string) $payment->fx_rate),
        ], $payment->currency_code);

        $this->posting->post(PostingRequest::make(
            entryDate: $payment->paid_at->toDateString(),
            branch: Branch::query()->findOrFail($payment->branch_id),
            currency: $payment->currency_code,
            lines: $lines,
            memo: 'Supplier payment '.$payment->public_id,
            sourceType: 'supplier_payment',
            sourceId: $payment->id,
            event: 'supplier_payment',
            fxRate: (string) $payment->fx_rate,
            fxRateDate: $payment->fx_rate_date,
            fxRateSource: $payment->fx_rate_source,
            actorId: $actorId,
        ));
    }

    private function postAdvance(SupplierPayment $payment, Account $cashAccount, int $amount, ?int $actorId): void
    {
        $advanceAccount = $this->posting->account($this->advanceAccountCode());

        $this->posting->post(PostingRequest::make(
            entryDate: $payment->paid_at->toDateString(),
            branch: Branch::query()->findOrFail($payment->branch_id),
            currency: $payment->currency_code,
            lines: [
                PostingLine::debit($advanceAccount, $amount, 'supplier', $payment->supplier_id, 'Supplier advance '.$payment->public_id),
                PostingLine::credit($cashAccount, $amount, null, null, 'Supplier advance '.$payment->public_id),
            ],
            memo: 'Supplier advance '.$payment->public_id,
            sourceType: 'supplier_payment',
            sourceId: $payment->id,
            event: 'supplier_advance',
            fxRate: (string) $payment->fx_rate,
            fxRateDate: $payment->fx_rate_date,
            fxRateSource: $payment->fx_rate_source,
            actorId: $actorId,
        ));
    }

    private function apAccountCode(): string
    {
        return (string) $this->settings->get('accounting', 'ap_account_code', '2100');
    }

    /**
     * PENDING ACCOUNTANT APPROVAL: supplier advances are an asset until they
     * are applied to an obligation. The account is configurable per install.
     */
    private function advanceAccountCode(): string
    {
        return (string) $this->settings->get('accounting', 'supplier_advance_account_code', '1150');
    }

    private function branch(?string $publicId): Branch
    {
        $branch = $publicId ? Branch::query()->where('public_id', $publicId)->first() : null;
        if (! $branch) {
            throw new DomainException('Branch not found.');
        }

        return $branch;
    }

    private function supplier(?string $publicId): Supplier
    {
        $supplier = $publicId ? Supplier::query()->where('public_id', $publicId)->first() : null;
        if (! $supplier) {
            throw new DomainException('Supplier not found.');
        }

        return $supplier;
    }

    private function obligation(?string $publicId, Supplier $supplier, Branch $branch, string $currency): SupplierObligation
    {
        $obligation = $publicId ? SupplierObligation::query()->where('public_id', $publicId)->first() : null;
        if (! $obligation) {
            throw new DomainException('Supplier obligation not found.');
        }

        if ($obligation->supplier_id !== $supplier->id) {
            throw new DomainException('The obligation belongs to another supplier.');
        }

        if ($obligation->branch_id !== $branch->id) {
            throw new DomainException('The obligation belongs to another branch.');
        }

        if (strtoupper((string) $obligation->currency_code) !== $currency) {
            throw new DomainException('Payment currency must match the obligation currency.');
        }

        if (! in_array($obligation->status, ['open', 'partially_paid'], true)) {
            throw new DomainException("Obligation {$obligation->public_id} is {$obligation->status} and cannot be settled.");
        }

        return $obligation;
    }

    private function cashAccount(?string $publicId): Account
    {
        $account = $publicId ? Account::query()->where('public_id', $publicId)->first() : null;

        if (! $account || ! $account->is_active || ! $account->is_postable || $account->type !== 'asset') {
            throw new DomainException('Cash account not found or not postable.');
        }

        return $account;
    }

    private function bookingItem(?string $id, Branch $branch, Supplier $supplier): ?BookingItem
    {
        if ($id === null || $id === '') {
            return null;
        }

        $item = BookingItem::query()->find((int) $id);
        if (! $item) {
            throw new DomainException('Booking item not found.');
        }

        $booking = $item->booking;
        if ($booking && $booking->branch_id !== $branch->id) {
            throw new DomainException('Booking item belongs to another branch.');
        }

        if ($item->supplier_id !== null && $item->supplier_id !== $supplier->id) {
            throw new DomainException('Booking item belongs to another supplier.');
        }

        return $item;
    }

    private function assertActor(?int $actorId, Branch $branch): void
    {
        if ($actorId === null) {
            return;
        }

        $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
    }
}
