<?php

namespace App\Http\Controllers\Api;

use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Core\Services\AuditService;
use App\Support\Like;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TourPackageController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branchOrFail();
        $packages = TourPackage::query()
            ->with('departures.package')
            ->where('branch_id', $branch->id)
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->query('execution_type'), fn ($query, $type) => $query->where('execution_type', $type))
            // `status` is the package's active flag: active or inactive.
            ->when(in_array($request->query('status'), ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->query('status') === 'active'))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['code', 'name', 'destination'], $search))
            ->orderByDesc('id')->get()
            ->map(fn (TourPackage $package) => $package->toApiArray());

        return $this->ok($packages);
    }

    public function show(TourPackage $package): JsonResponse
    {
        $this->authorizeBranchId($package->branch_id);

        return $this->ok($package->load('departures.package')->toApiArray());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(['domestic', 'international', 'umrah', 'hajj', 'corporate', 'day_trip', 'other'])],
            'execution_type' => ['required', Rule::in(['fixed', 'private', 'standalone'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'destination' => ['nullable', 'string', 'max:200'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'base_price_minor' => ['required', 'integer', 'min:0'],
            'estimated_cost_minor' => ['required', 'integer', 'min:0'],
        ]);
        $branch = $this->branchOrFail();
        $package = TourPackage::query()->create([
            'branch_id' => $branch->id,
            'code' => 'TOUR-'.Str::ulid(),
            'name' => $data['name'],
            'category' => $data['category'],
            'execution_type' => $data['execution_type'],
            'description' => $data['description'] ?? null,
            'destination' => $data['destination'] ?? null,
            'currency_code' => $data['currency'],
            'base_price_minor' => $data['base_price_minor'],
            'estimated_cost_minor' => $data['estimated_cost_minor'],
            'created_by' => $this->actorId(),
        ]);

        return $this->created($package->toApiArray());
    }

    /** PATCH /tour-packages/{package}: edit a program (ADR-0037). Prices apply to new bookings only; bookings keep theirs. */
    public function update(Request $request, TourPackage $package, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($package->branch_id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'category' => ['sometimes', Rule::in(['domestic', 'international', 'umrah', 'hajj', 'corporate', 'day_trip', 'other'])],
            'execution_type' => ['sometimes', Rule::in(['fixed', 'private', 'standalone'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:200'],
            'base_price_minor' => ['sometimes', 'integer', 'min:0'],
            'estimated_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name']) && TourPackage::query()->where('branch_id', $package->branch_id)->where('id', '!=', $package->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])->exists()) {
            throw new DomainException('Another program already has this name.');
        }
        if (isset($data['execution_type']) && $data['execution_type'] !== $package->execution_type && $package->departures()->exists()) {
            throw new DomainException('The type cannot change once the program has departures.');
        }

        $package->fill($data)->save();
        $audit->log('tour_package.updated', $package, null, ['fields' => array_keys($data)], $this->actorId());

        return $this->ok($package->load('departures.package')->toApiArray());
    }

    public function departure(Request $request, TourPackage $package): JsonResponse
    {
        $this->authorizeBranchId($package->branch_id);
        if ($package->execution_type !== 'fixed') {
            throw new DomainException('Only fixed-departure packages can have scheduled departures.');
        }
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'capacity' => ['required', 'integer', 'min:1'],
        ]);
        $departure = TourDeparture::query()->create([
            'tour_package_id' => $package->id,
            'branch_id' => $package->branch_id,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'capacity' => $data['capacity'],
            'status' => 'open',
        ]);

        return $this->created($departure->load('package')->toApiArray());
    }

    /** PATCH /tour-departures/{departure}: change the dates or the seats of a departure (ADR-0037). */
    public function updateDeparture(Request $request, TourDeparture $departure, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($departure->branch_id);
        $data = $request->validate([
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'],
        ]);

        // BookingService::transition() locks the departure while it confirms seats; take the same lock before
        // checking, so a capacity cut cannot race a confirmation and leave more passengers than seats.
        $departure = DB::transaction(function () use ($departure, $data, $audit) {
            $locked = TourDeparture::query()->lockForUpdate()->findOrFail($departure->id);
            $starts = Carbon::parse($data['starts_on'] ?? $locked->starts_on);
            $ends = array_key_exists('ends_on', $data) ? ($data['ends_on'] ? Carbon::parse($data['ends_on']) : null) : $locked->ends_on;
            if ($ends !== null && $ends->lt($starts)) {
                throw new DomainException('The return date is before the departure date.');
            }
            if (isset($data['capacity']) && $data['capacity'] < $locked->occupiedSeats()) {
                throw new DomainException('The seats cannot be fewer than the '.$locked->occupiedSeats().' already taken.');
            }
            // A booking on a departure must start on its date, so the date moves only while nobody holds a place.
            if (isset($data['starts_on']) && $starts->toDateString() !== $locked->starts_on->toDateString()
                && $locked->bookings()->whereNotIn('status', ['cancelled', 'no_show'])->exists()) {
                throw new DomainException('This departure has bookings: move or cancel them before changing its date.');
            }

            $locked->update(array_filter([
                'starts_on' => isset($data['starts_on']) ? $starts->toDateString() : null,
                'capacity' => $data['capacity'] ?? null,
            ], fn ($v) => $v !== null) + (array_key_exists('ends_on', $data) ? ['ends_on' => $ends?->toDateString()] : []));
            $audit->log('tour_departure.updated', $locked, null, ['fields' => array_keys($data)], $this->actorId());

            return $locked;
        });

        return $this->ok($departure->load('package')->toApiArray());
    }

    public function reopen(TourDeparture $departure, AuditService $audit): JsonResponse
    {
        $this->authorizeBranchId($departure->branch_id);
        $departure->update(['status' => 'open']);
        $audit->log('tour_departure.reopened', $departure, null, null, $this->actorId());

        return $this->ok($departure->load('package')->toApiArray());
    }

    public function close(TourDeparture $departure): JsonResponse
    {
        $this->authorizeBranchId($departure->branch_id);
        $departure->update(['status' => 'closed']);

        return $this->ok($departure->load('package')->toApiArray());
    }
}
