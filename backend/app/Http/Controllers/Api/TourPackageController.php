<?php

namespace App\Http\Controllers\Api;

use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Bookings\Models\TourPackage;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function close(TourDeparture $departure): JsonResponse
    {
        $this->authorizeBranchId($departure->branch_id);
        $departure->update(['status' => 'closed']);

        return $this->ok($departure->load('package')->toApiArray());
    }
}
