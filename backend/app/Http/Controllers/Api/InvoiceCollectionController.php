<?php

namespace App\Http\Controllers\Api;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Services\PaymentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Collect full or partial invoices with one or more cash / card / bank
 * receipts in one atomic action. Every tranche creates its own receipt and
 * linked application, preserving distinct payment methods and journal lines.
 */
class InvoiceCollectionController extends ApiController
{
    public function store(Request $request, Invoice $invoice, PaymentService $payments, InvoiceSettlementService $settlements): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);
        $data = $request->validate([
            'receipts' => ['required', 'array', 'min:1', 'max:20'],
            'receipts.*.amount_minor' => ['required', 'integer', 'min:1'],
            'receipts.*.cash_account_id' => ['required', 'string', Rule::exists('accounts', 'public_id')],
            'receipts.*.payment_method_id' => ['required', 'string', Rule::exists('payment_methods', 'public_id')],
            'receipts.*.paid_at' => ['required', 'date'],
            'receipts.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $result = DB::transaction(function () use ($invoice, $data, $payments, $settlements, $request) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->type !== Invoice::TYPE_CUSTOMER_INVOICE || $invoice->status !== Invoice::STATUS_ISSUED
                || $invoice->journal_entry_id === null || $invoice->party_type !== 'customer') {
                throw new DomainException('Only issued customer invoices can receive collections.');
            }
            $total = array_sum(array_column($data['receipts'], 'amount_minor'));
            if ($total <= 0 || $total > (int) $invoice->outstanding_minor) {
                throw new DomainException('Collection total exceeds the outstanding amount of this invoice.');
            }
            $customer = Customer::query()->findOrFail($invoice->party_id);
            $branch = $invoice->branch;
            $collection = [];
            foreach ($data['receipts'] as $index => $receipt) {
                if ($invoice->currency_code !== 'EGP' && ! isset($receipt['fx_rate'])) {
                    throw new DomainException('An explicit current exchange rate is required for foreign-currency collections.');
                }

                $payment = $payments->record([
                    'branch_id' => $branch->public_id,
                    'customer_id' => $customer->public_id,
                    'cash_account_id' => $receipt['cash_account_id'],
                    'payment_method_id' => $receipt['payment_method_id'],
                    'amount_minor' => (int) $receipt['amount_minor'],
                    'currency' => $invoice->currency_code,
                    'fx_rate' => $receipt['fx_rate'] ?? '1',
                    'paid_at' => $receipt['paid_at'],
                    'allocations' => [],
                ], $this->actorId(), $request->header('Idempotency-Key')
                    ? $request->header('Idempotency-Key').':'.($index + 1)
                    : null);

                $application = $settlements->applyReceipt($payment, $invoice, (int) $receipt['amount_minor'], $this->actorId());
                $collection[] = [
                    'receipt' => $payment->toApiArray(),
                    'application' => $application->toApiArray(),
                ];
            }

            return [
                'invoice' => $invoice->refresh()->load('lines.account', 'party')->toApiArray(true),
                'receipts' => $collection,
            ];
        }, 3);

        return $this->created($result);
    }
}
