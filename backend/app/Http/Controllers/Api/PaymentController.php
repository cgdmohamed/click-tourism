<?php

namespace App\Http\Controllers\Api;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(Payment $payment): JsonResponse
    {
        $this->authorizeBranchId($payment->branch_id);
        $payment->load('customer', 'booking', 'allocations');

        return $this->ok($payment->toApiArray());
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
            'currency' => ['required', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.booking_id' => ['required', 'string'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $payments->record($data, $this->actorId(), $request->header('Idempotency-Key'));

        return $this->created($payment->toApiArray());
    }
}
