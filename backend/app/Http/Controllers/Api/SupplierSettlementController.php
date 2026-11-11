<?php

namespace App\Http\Controllers\Api;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Payments\Services\SupplierSettlementService;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierSettlementController extends ApiController
{
    public function obligations(Request $request): JsonResponse
    {
        $branch = $this->branchOrFail();

        $obligations = SupplierObligation::query()
            ->with('supplier', 'branch')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('supplier_id'), fn ($query, $supplierId) => $query->whereHas(
                'supplier',
                fn ($query) => $query->where('public_id', $supplierId),
            ))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            // Payable picker: unpaid obligations backed by an issued supplier invoice.
            ->when($request->boolean('payable'), fn ($query) => $query
                ->whereIn('status', ['open', 'partially_paid'])
                ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_ISSUED)))
            ->when($request->query('currency'), fn ($query, $currency) => $query->where('currency_code', strtoupper((string) $currency)))
            ->when(Like::term($request->query('search')), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    Like::contains($query, ['invoice_reference'], $search);
                    $query->orWhereHas('supplier', fn ($supplier) => Like::contains($supplier, ['name_ar', 'name_en'], $search));
                });
            })
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $obligations->getCollection()->transform(fn (SupplierObligation $obligation) => $obligation->toApiArray());

        return $this->paginated($obligations);
    }

    public function showObligation(SupplierObligation $supplierObligation): JsonResponse
    {
        $this->assertBranch($supplierObligation->branch_id);

        $supplierObligation->load('supplier', 'branch', 'bookingItem', 'applications');

        return $this->ok($supplierObligation->toApiArray());
    }

    public function storeObligation(Request $request, SupplierSettlementService $settlements): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'booking_item_id' => ['nullable', 'string', 'max:40'],
            'invoice_id' => ['nullable', 'string'],
        ]);

        $obligation = $settlements->createObligation($data, $this->actorId());

        return $this->created($obligation->toApiArray());
    }

    public function recognizeObligation(Request $request, SupplierObligation $supplierObligation, SupplierSettlementService $settlements): JsonResponse
    {
        $this->assertBranch($supplierObligation->branch_id);
        $data = $request->validate(['invoice_id' => ['required', 'string']]);

        return $this->ok($settlements->recognizeObligation($supplierObligation, $data['invoice_id'], $this->actorId())->toApiArray());
    }

    public function payments(Request $request): JsonResponse
    {
        $branch = $this->branchOrFail();

        $payments = SupplierPayment::query()
            ->with('supplier', 'branch', 'obligation', 'cashAccount')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('supplier_id'), fn ($query, $supplierId) => $query->whereHas(
                'supplier',
                fn ($query) => $query->where('public_id', $supplierId),
            ))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('paid_at', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('paid_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $payments->getCollection()->transform(fn (SupplierPayment $payment) => $payment->toApiArray());

        return $this->paginated($payments);
    }

    public function showPayment(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->assertBranch($supplierPayment->branch_id);

        $supplierPayment->load('supplier', 'branch', 'obligation', 'cashAccount', 'applications');

        return $this->ok($supplierPayment->toApiArray());
    }

    public function storePayment(Request $request, SupplierSettlementService $settlements): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string'],
            'supplier_obligation_id' => ['nullable', 'string'],
            'branch_id' => ['required', 'string'],
            'cash_account_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $payment = $settlements->recordPayment($data, $this->actorId(), $request->header('Idempotency-Key'));

        return $this->created($payment->toApiArray());
    }

    public function apply(Request $request, SupplierPayment $supplierPayment, SupplierSettlementService $settlements): JsonResponse
    {
        $this->assertBranch($supplierPayment->branch_id);

        $data = $request->validate([
            'supplier_obligation_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $settlements->applyAdvance($supplierPayment, $data, $this->actorId());

        return $this->ok($payment->toApiArray());
    }

    public function unapply(Request $request, SupplierPayment $supplierPayment, SupplierSettlementService $settlements): JsonResponse
    {
        $this->assertBranch($supplierPayment->branch_id);

        $data = $request->validate([
            'application_id' => ['required', 'string'],
        ]);

        $payment = $settlements->unapply($supplierPayment, $data['application_id'], $this->actorId());

        return $this->ok($payment->toApiArray());
    }

    public function cancel(Request $request, SupplierPayment $supplierPayment, SupplierSettlementService $settlements): JsonResponse
    {
        $this->assertBranch($supplierPayment->branch_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->ok($settlements->cancel($supplierPayment, $data['reason'], $this->actorId())->toApiArray());
    }

    private function assertBranch(int $branchId): void
    {
        $branch = $this->branchOrFail();

        if ($branch->id !== $branchId) {
            throw new AuthorizationException('This record belongs to another branch.');
        }
    }

    public function applyToInvoice(Request $request, SupplierPayment $supplierPayment, InvoiceSettlementService $settlement): JsonResponse
    {
        $this->authorizeBranchId($supplierPayment->branch_id);

        $data = $request->validate([
            'invoice_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $invoice = Invoice::query()->where('public_id', $data['invoice_id'])->firstOrFail();
        $this->authorizeBranchId($invoice->branch_id);
        $application = $settlement->applySupplierPayment($supplierPayment, $invoice, (int) $data['amount_minor'], $this->actorId());

        return $this->created($application->toApiArray());
    }
}
