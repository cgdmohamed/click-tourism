<?php

namespace App\Http\Controllers\Api;

use App\Modules\Bookings\Models\Booking;
use App\Modules\CRM\Models\Lead;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-entity quick search for the application header.
 *
 * Results are branch-scoped and permission-filtered: a user only sees a
 * group when they hold the matching ".view" permission. The endpoint never
 * exposes data that the corresponding list endpoint would reject.
 */
class SearchController extends ApiController
{
    private const LIMIT = 5;

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return $this->ok(['groups' => [], 'total' => 0]);
        }

        $user = $request->user();
        $branch = $this->branch();
        $like = '%'.$term.'%';
        $groups = [];

        if ($user->can('customers.view')) {
            $items = Customer::query()
                ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
                ->where(function (Builder $query) use ($like) {
                    $query->where('name_ar', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('name_ar')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Customer $customer) => [
                    'id' => $customer->public_id,
                    'title_ar' => $customer->name_ar,
                    'title_en' => $customer->name_en,
                    'subtitle' => $this->subtitle($customer->phone, $customer->email),
                ])
                ->all();

            if ($items !== []) {
                $groups[] = ['type' => 'customers', 'items' => $items];
            }
        }

        if ($user->can('suppliers.view')) {
            $items = Supplier::query()
                ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
                ->where(function (Builder $query) use ($like) {
                    $query->where('name_ar', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('name_ar')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->public_id,
                    'title_ar' => $supplier->name_ar,
                    'title_en' => $supplier->name_en,
                    'subtitle' => $this->subtitle($supplier->phone, $supplier->email),
                ])
                ->all();

            if ($items !== []) {
                $groups[] = ['type' => 'suppliers', 'items' => $items];
            }
        }

        if ($user->can('bookings.view')) {
            $items = Booking::query()
                ->with('customer')
                ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
                ->where(function (Builder $query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhereHas('customer', function (Builder $query) use ($like) {
                            $query->where('name_ar', 'like', $like)
                                ->orWhere('name_en', 'like', $like);
                        });
                })
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Booking $booking) => [
                    'id' => $booking->public_id,
                    'title_ar' => $this->joinTitle($booking->number, $booking->customer?->name_ar),
                    'title_en' => $this->joinTitle($booking->number, $booking->customer?->name_en),
                    'subtitle' => $booking->status,
                ])
                ->all();

            if ($items !== []) {
                $groups[] = ['type' => 'bookings', 'items' => $items];
            }
        }

        if ($user->can('payments.view')) {
            $items = Payment::query()
                ->with('customer')
                ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
                ->where(function (Builder $query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhereHas('customer', function (Builder $query) use ($like) {
                            $query->where('name_ar', 'like', $like)
                                ->orWhere('name_en', 'like', $like);
                        });
                })
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->public_id,
                    'title_ar' => $this->joinTitle($payment->number, $payment->customer?->name_ar),
                    'title_en' => $this->joinTitle($payment->number, $payment->customer?->name_en),
                    'subtitle' => $payment->status,
                ])
                ->all();

            if ($items !== []) {
                $groups[] = ['type' => 'payments', 'items' => $items];
            }
        }

        if ($user->can('leads.view')) {
            $items = Lead::query()
                ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
                ->where(function (Builder $query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => $lead->public_id,
                    'title_ar' => $lead->name,
                    'title_en' => $lead->name,
                    'subtitle' => $this->subtitle($lead->phone, $lead->email),
                ])
                ->all();

            if ($items !== []) {
                $groups[] = ['type' => 'leads', 'items' => $items];
            }
        }

        return $this->ok([
            'groups' => $groups,
            'total' => array_sum(array_map(fn (array $group) => count($group['items']), $groups)),
        ]);
    }

    private function subtitle(?string ...$values): string
    {
        return implode(' · ', array_values(array_filter($values)));
    }

    private function joinTitle(string $prefix, ?string $suffix): string
    {
        return $suffix ? $prefix.' · '.$suffix : $prefix;
    }
}
