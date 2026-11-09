<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Core\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FiscalPeriodController extends ApiController
{
    public function index(): JsonResponse
    {
        $years = FiscalYear::query()
            ->with(['periods' => fn ($query) => $query->orderBy('starts_on')])
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (FiscalYear $year) => [
                'id' => (string) $year->id,
                'name' => $year->name,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'periods' => $year->periods->map(fn (FiscalPeriod $period) => [
                    'id' => (string) $period->id,
                    'name' => $period->name,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'status' => $period->status,
                ])->values(),
            ]);

        return $this->ok($years);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $year = DB::transaction(function () use ($data) {
            $year = FiscalYear::query()->create([
                'name' => $data['name'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
            ]);

            $cursor = Carbon::parse($data['starts_on'])->startOfMonth();
            $end = Carbon::parse($data['ends_on']);
            $index = 1;

            while ($cursor->lessThanOrEqualTo($end)) {
                $periodEnd = $cursor->copy()->endOfMonth();
                if ($periodEnd->greaterThan($end)) {
                    $periodEnd = $end->copy();
                }

                FiscalPeriod::query()->create([
                    'fiscal_year_id' => $year->id,
                    'name' => $cursor->format('Y-m'),
                    'starts_on' => $cursor->toDateString(),
                    'ends_on' => $periodEnd->toDateString(),
                    'status' => 'open',
                ]);

                $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
                $index++;
            }

            return $year->load('periods');
        });

        $audit->log('fiscal_year.created', $year, null, ['name' => $year->name], $this->actorId());

        return $this->created([
            'id' => (string) $year->id,
            'name' => $year->name,
            'periods' => $year->periods->map(fn (FiscalPeriod $period) => [
                'id' => (string) $period->id,
                'name' => $period->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'status' => $period->status,
            ])->values(),
        ]);
    }

    public function updateStatus(Request $request, FiscalPeriod $period, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,soft_closed,closed'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $period->status;
        $period->update(['status' => $data['status']]);

        $audit->log('fiscal_period.status_changed', $period, ['status' => $before], [
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
        ], $this->actorId());

        return $this->ok([
            'id' => (string) $period->id,
            'status' => $period->status,
        ]);
    }
}
