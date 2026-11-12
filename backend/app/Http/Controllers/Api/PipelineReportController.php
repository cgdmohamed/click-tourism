<?php

namespace App\Http\Controllers\Api;

use App\Modules\CRM\Services\PipelineReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GAP-CRM-05: pipeline metrics for leads captured in a period, grouped by
 * stage, source, and owner. Branch scoping follows X-Branch-Id.
 */
class PipelineReportController extends ApiController
{
    public function __invoke(Request $request, PipelineReportService $pipeline): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'thisMonth', 'lastMonth', 'customRange'])],
            'from' => ['required_if:period,customRange', 'nullable', 'date'],
            'to' => ['required_if:period,customRange', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        return $this->ok($pipeline->report($this->branch(), $data));
    }
}
