<?php

namespace App\Http\Controllers\Api;

use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $refunds = Refund::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $refunds->getCollection()->transform(fn (Refund $refund) => $this->payload($refund));

        return $this->paginated($refunds);
    }

    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'string'],
            'booking_id' => ['nullable', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $refund = $payments->requestRefund($data, $this->actorId());

        return $this->created($this->payload($refund));
    }

    public function approve(Request $request, Refund $refund, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['fx_rate' => ['nullable', 'numeric', 'gt:0']]);
        $refund = $payments->approveRefund($refund, (int) $this->actorId(), isset($data['fx_rate']) ? (string) $data['fx_rate'] : null);

        return $this->ok($this->payload($refund));
    }

    private function payload(Refund $refund): array
    {
        return [
            'id' => $refund->public_id,
            'number' => $refund->number,
            'payment_id' => $refund->payment?->public_id,
            'booking_id' => $refund->booking?->public_id,
            'amount_minor' => (int) $refund->amount_minor,
            'currency' => $refund->currency_code,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'approved_at' => $refund->approved_at?->toIso8601String(),
        ];
    }
}
