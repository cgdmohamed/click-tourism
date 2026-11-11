<?php

namespace App\Http\Controllers\Api;

use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Http\JsonResponse;

class TaxProfileController extends ApiController
{
    /** Read-only list of active tax profiles for pickers (booking items). */
    public function index(): JsonResponse
    {
        $profiles = TaxProfile::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderBy('name')
            ->get()
            ->map(fn (TaxProfile $profile) => $profile->toApiArray())
            ->values();

        return $this->ok($profiles);
    }
}
