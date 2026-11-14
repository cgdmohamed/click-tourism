<?php

namespace App\Http\Controllers\Api;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $payments = Payment::query()
            ->with('customer', 'booking')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('customer_id'), fn ($query, $customerId) => $query->whereHas('customer', fn ($query) => $query->where('public_id', $customerId)))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('name_ar', 'like', "%{$search}%")
                                ->orWhere('name_en', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->query('from'), fn ($query, $from) => $query->where('paid_at', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('paid_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $payments->getCollection()->transform(fn (Payment $payment) => $payment->toApiArray());

        return $this->paginated($payments);
    }

    /**
     * Open (issued, still owing) customer invoices of one customer in the active branch, for the receipt and
     * apply dialogs (W3). Needs payments.create, not invoices.view, and returns only what those dialogs show.
     */
    public function openInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        // Fail closed: a user with no branch assignment sees no operational data (ADR-0005).
        $branch = $this->branchOrFail();
        $customerId = Customer::query()->where('public_id', $data['customer_id'])->value('id');

        $invoices = Invoice::query()
            ->where('branch_id', $branch->id)
            ->where('type', Invoice::TYPE_CUSTOMER_INVOICE)
            ->where('party_type', 'customer')
            ->where('party_id', $customerId ?? 0)
            ->where('status', Invoice::STATUS_ISSUED)
            ->where('outstanding_minor', '>', 0)
            ->when($data['currency'] ?? null, fn ($query, $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when(Like::term($data['search'] ?? null), fn ($query, $search) => Like::contains($query, ['number'], $search))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $invoices->getCollection()->transform(fn (Invoice $invoice) => [
            'id' => $invoice->public_id,
            'number' => $invoice->number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency_code,
            'total_minor' => (int) $invoice->total_minor,
            'outstanding_minor' => (int) $invoice->outstanding_minor,
        ]);

        return $this->paginated($invoices);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);
        $payment->load('customer', 'booking', 'allocations');

        return $this->ok($payment->toApiArray());
    }

    public function allocate(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.booking_id' => ['required', 'string'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        return $this->ok($payments->allocate($payment, $data['allocations'], $this->actorId()));
    }

    public function cancel(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->ok($payments->cancel($payment, $data['reason'], $this->actorId())->load('customer', 'booking')->toApiArray());
    }

    public function unallocate(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);

        $data = $request->validate([
            'booking_id' => ['required', 'string'],
        ]);

        return $this->ok($payments->unallocate($payment, $data['booking_id'], $this->actorId()));
    }

    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'booking_id' => ['nullable', 'string'],
            'branch_id' => ['required', 'string'],
            'cash_account_id' => ['required', 'string'],
            'method' => ['nullable', 'in:cash,bank_transfer,card,wallet'],
            'payment_method_id' => ['nullable', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'fx_rate' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.booking_id' => ['required', 'string'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $payments->record($data, $this->actorId(), $request->header('Idempotency-Key'));

        return $this->created($payment->toApiArray());
    }

    public function applyToInvoice(Request $request, Payment $payment, InvoiceSettlementService $settlement): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);

        $data = $request->validate([
            'invoice_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $invoice = Invoice::query()->where('public_id', $data['invoice_id'])->firstOrFail();
        $this->authorizeBranchId($invoice->branch_id);
        $application = $settlement->applyReceipt($payment, $invoice, (int) $data['amount_minor'], $this->actorId());

        return $this->created($application->toApiArray());
    }
}
