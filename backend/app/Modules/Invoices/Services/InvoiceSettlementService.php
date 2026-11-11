<?php

namespace App\Modules\Invoices\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Accounting\Services\RealizedFxService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Payments\Models\SupplierPayment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Settles issued invoices with receipts (customers) or supplier advances
 * (suppliers). Each application posts one balanced entry that moves the
 * amount from the party's advance account to the party's own receivable or
 * payable subaccount, and reduces the invoice's outstanding amount, so the
 * invoice, the party statement and the ledger always agree.
 */
class InvoiceSettlementService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly PartyLedgerService $ledger,
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly RealizedFxService $realizedFx,
    ) {}

    public function applyReceipt(Payment $payment, Invoice $invoice, int $amount, ?int $actorId): InvoiceApplication
    {
        return DB::transaction(function () use ($payment, $invoice, $amount, $actorId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $branch = Branch::query()->findOrFail($payment->branch_id);
            $this->assertActor($actorId, $branch);

            if ($payment->status !== 'posted') {
                throw new DomainException('Only posted receipts can be applied to invoices.');
            }
            if ($invoice->type !== Invoice::TYPE_CUSTOMER_INVOICE || $invoice->party_type !== 'customer') {
                throw new DomainException('A receipt can only settle a customer invoice.');
            }
            $this->assertApplicable($invoice, $amount, $payment->branch_id, $payment->currency_code);
            if ((int) $invoice->party_id !== (int) $payment->customer_id) {
                throw new DomainException("Cannot apply a receipt to another customer's invoice.");
            }
            if ($amount > (int) $payment->unallocated_minor) {
                throw new DomainException('The amount exceeds the unallocated part of this receipt.');
            }

            $application = InvoiceApplication::query()->create([
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'created_by' => $actorId,
            ]);

            $advance = $this->posting->account((string) $this->settings->get('accounting', 'advance_account_code', '2300'));
            $receivable = $this->ledger->receivable($payment->customer_id);
            $label = 'Apply receipt '.$payment->number.' to '.$invoice->number;

            $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: $branch,
                currency: $payment->currency_code,
                lines: $this->realizedFx->balance([
                    PostingLine::debit($advance, $amount, 'customer', $payment->customer_id, $label)
                        ->in($payment->currency_code, (string) $payment->fx_rate),
                    PostingLine::credit($receivable, $amount, 'customer', $payment->customer_id, $label)
                        ->in($invoice->currency_code, (string) $invoice->fx_rate),
                ], $payment->currency_code),
                memo: $label,
                sourceType: 'invoice_application',
                sourceId: $application->id,
                event: 'applied',
                fxRate: (string) $payment->fx_rate,
                fxRateDate: $payment->fx_rate_date,
                fxRateSource: $payment->fx_rate_source,
                actorId: $actorId,
            ));

            $payment->update(['unallocated_minor' => (int) $payment->unallocated_minor - $amount]);
            $invoice->update(['outstanding_minor' => (int) $invoice->outstanding_minor - $amount]);

            $this->audit->log('invoice.settled', $invoice, null, [
                'payment' => $payment->number,
                'amount_minor' => $amount,
                'outstanding_minor' => $invoice->outstanding_minor,
            ], $actorId);

            return $application->load('invoice', 'payment');
        });
    }

    public function applySupplierPayment(SupplierPayment $payment, Invoice $invoice, int $amount, ?int $actorId): InvoiceApplication
    {
        return DB::transaction(function () use ($payment, $invoice, $amount, $actorId) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $branch = Branch::query()->findOrFail($payment->branch_id);
            $this->assertActor($actorId, $branch);

            if ($payment->status !== 'posted') {
                throw new DomainException('Only posted supplier payments can be applied to invoices.');
            }
            if ($invoice->type !== Invoice::TYPE_SUPPLIER_INVOICE || $invoice->party_type !== 'supplier') {
                throw new DomainException('A supplier payment can only settle a supplier invoice.');
            }
            if (SupplierObligation::query()->where('invoice_id', $invoice->id)->exists()) {
                throw new DomainException('Invoice is linked to an operational supplier obligation; settle or apply the advance through that obligation to prevent duplicate applications.');
            }
            $this->assertApplicable($invoice, $amount, $payment->branch_id, $payment->currency_code);
            if ((int) $invoice->party_id !== (int) $payment->supplier_id) {
                throw new DomainException("Cannot apply a payment to another supplier's invoice.");
            }
            if ($amount > $payment->unallocatedMinor()) {
                throw new DomainException('The amount exceeds the unapplied part of this supplier payment.');
            }

            $application = InvoiceApplication::query()->create([
                'invoice_id' => $invoice->id,
                'supplier_payment_id' => $payment->id,
                'branch_id' => $branch->id,
                'amount_minor' => $amount,
                'created_by' => $actorId,
            ]);

            $advance = $this->posting->account((string) $this->settings->get('accounting', 'supplier_advance_account_code', '1153'));
            $payable = $this->ledger->payable($payment->supplier_id);
            $label = 'Apply supplier payment '.$payment->public_id.' to '.$invoice->number;

            $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: $branch,
                currency: $payment->currency_code,
                lines: $this->realizedFx->balance([
                    PostingLine::debit($payable, $amount, 'supplier', $payment->supplier_id, $label)
                        ->in($invoice->currency_code, (string) $invoice->fx_rate),
                    PostingLine::credit($advance, $amount, 'supplier', $payment->supplier_id, $label)
                        ->in($payment->currency_code, (string) $payment->fx_rate),
                ], $payment->currency_code),
                memo: $label,
                sourceType: 'invoice_application',
                sourceId: $application->id,
                event: 'applied',
                fxRate: (string) $payment->fx_rate,
                fxRateDate: $payment->fx_rate_date,
                fxRateSource: $payment->fx_rate_source,
                actorId: $actorId,
            ));

            $invoice->update(['outstanding_minor' => (int) $invoice->outstanding_minor - $amount]);

            $this->audit->log('invoice.settled', $invoice, null, [
                'supplier_payment' => $payment->public_id,
                'amount_minor' => $amount,
                'outstanding_minor' => $invoice->outstanding_minor,
            ], $actorId);

            return $application->load('invoice', 'supplierPayment');
        });
    }

    /**
     * Undo an application: the entry is reversed (never deleted), the amount
     * returns to the receipt/advance and the invoice's outstanding grows back.
     */
    public function unapply(InvoiceApplication $application, string $reason, ?int $actorId): InvoiceApplication
    {
        return DB::transaction(function () use ($application, $reason, $actorId) {
            $application = InvoiceApplication::query()->lockForUpdate()->findOrFail($application->id);
            $branch = Branch::query()->findOrFail($application->branch_id);
            $this->assertActor($actorId, $branch);

            if ($application->reversed_at !== null) {
                throw new DomainException('This application has already been undone.');
            }
            if (trim($reason) === '') {
                throw new DomainException('A reason is required.');
            }

            $entry = JournalEntry::query()
                ->where('source_type', 'invoice_application')
                ->where('source_id', $application->id)
                ->where('event', 'applied')
                ->whereNull('reversal_of_id')
                ->firstOrFail();
            $this->posting->reverse($entry, $reason, $actorId);

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($application->invoice_id);
            $invoice->update(['outstanding_minor' => (int) $invoice->outstanding_minor + (int) $application->amount_minor]);

            if ($application->payment_id !== null) {
                $payment = Payment::query()->lockForUpdate()->findOrFail($application->payment_id);
                $payment->update(['unallocated_minor' => (int) $payment->unallocated_minor + (int) $application->amount_minor]);
            }

            $application->update([
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversed_by' => $actorId,
            ]);

            $this->audit->log('invoice.unsettled', $invoice, null, [
                'amount_minor' => (int) $application->amount_minor,
                'reason' => $reason,
            ], $actorId);

            return $application->load('invoice');
        });
    }

    private function assertApplicable(Invoice $invoice, int $amount, int $branchId, string $currency): void
    {
        if ($amount <= 0) {
            throw new DomainException('The amount must be greater than zero.');
        }
        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            throw new DomainException('Only issued invoices can be settled.');
        }
        if ((int) $invoice->branch_id !== $branchId) {
            throw new DomainException('The invoice belongs to another branch.');
        }
        if (strtoupper($invoice->currency_code) !== strtoupper($currency)) {
            throw new DomainException('The payment currency must match the invoice currency.');
        }
        if ($amount > (int) $invoice->outstanding_minor) {
            throw new DomainException("The amount exceeds the outstanding balance of invoice {$invoice->number}.");
        }
    }

    private function assertActor(?int $actorId, Branch $branch): void
    {
        if ($actorId !== null) {
            $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
        }
    }
}
