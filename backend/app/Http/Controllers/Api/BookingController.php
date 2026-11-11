<?php

namespace App\Http\Controllers\Api;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $bookings = Booking::query()
            ->with(['customer', 'items.supplier', 'items.taxProfile'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('customer_id'), function ($query, $customerId) {
                $customer = Customer::query()->where('public_id', $customerId)->first();
                $query->where('customer_id', $customer?->id ?? 0);
            })
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($query) => $query->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $bookings->getCollection()->transform(fn (Booking $booking) => $booking->toApiArray(true));

        return $this->paginated($bookings);
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->authorizeBranchId($booking->branch_id);
        $booking->load('customer', 'items.supplier', 'items.taxProfile', 'statusHistory');

        $payload = $booking->toApiArray(true);
        $payload['payments_count'] = (int) $booking->allocations()->distinct()->count('payment_id');
        $payload['invoices_count'] = Invoice::query()->where('booking_id', $booking->id)->count();

        return $this->ok($payload);
    }

    public function store(Request $request, BookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'owner_user_id' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'pax_adults' => ['nullable', 'integer', 'min:1'],
            'pax_children' => ['nullable', 'integer', 'min:0'],
            'pax_infants' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_type' => ['required', 'in:flight,hotel,transport,visa,program,other'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost_minor' => ['required', 'integer', 'min:0'],
            'items.*.unit_sell_minor' => ['required', 'integer', 'min:0'],
            'items.*.cost_currency' => ['nullable', 'string', 'size:3'],
            'items.*.sell_currency' => ['nullable', 'string', 'size:3'],
            'items.*.supplier_id' => ['nullable', 'string'],
            'items.*.tax_profile_id' => ['nullable', 'regex:/^[0-9A-Za-z]+$/'],
        ]);

        $booking = $bookings->create($data, $this->actorId());

        return $this->created($booking->toApiArray(true));
    }

    public function transition(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:quoted,confirmed,in_progress,completed,closed,cancelled,no_show'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $booking = $bookings->transition($booking, $data['status'], $data['reason'] ?? null, $this->actorId());

        return $this->ok($booking->toApiArray(true));
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'cancellation_fee_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $booking = $bookings->cancel(
            $booking,
            $data['reason'] ?? 'Customer cancellation',
            (int) ($data['cancellation_fee_minor'] ?? 0),
            $this->actorId(),
        );

        return $this->ok($booking->toApiArray(true));
    }

    public function requestMarginApproval(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $this->authorizeBranchId($booking->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->ok($bookings->requestMarginApproval($booking, $data['reason'], $this->actorId())->toApiArray(true));
    }

    public function approveMargin(Booking $booking, BookingService $bookings): JsonResponse
    {
        $this->authorizeBranchId($booking->branch_id);

        return $this->ok($bookings->approveMargin($booking, $this->actorId())->toApiArray(true));
    }
}
