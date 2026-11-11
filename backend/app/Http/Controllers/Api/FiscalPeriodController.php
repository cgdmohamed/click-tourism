<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FiscalCloseService;
use App\Modules\Accounting\Services\FiscalYearSuggestionService;
use App\Modules\Accounting\Services\PeriodEndChecklistService;
use App\Modules\Core\Services\AuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                'status' => $year->status,
                'closed_at' => $year->closed_at?->toIso8601String(),
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

    public function suggested(FiscalYearSuggestionService $suggestions): JsonResponse
    {
        return $this->ok($suggestions->suggest());
    }

    public function closeChecks(FiscalYear $fiscalYear, FiscalCloseService $closing): JsonResponse
    {
        return $this->ok($closing->checks($fiscalYear));
    }

    public function closePreview(FiscalYear $fiscalYear, FiscalCloseService $closing): JsonResponse
    {
        return $this->ok($closing->closePreview($fiscalYear));
    }

    public function checklist(FiscalPeriod $period, PeriodEndChecklistService $checklist): JsonResponse
    {
        return $this->ok($checklist->forPeriod($period, $this->branch()));
    }

    public function close(FiscalYear $fiscalYear, FiscalCloseService $closing): JsonResponse
    {
        return $this->ok($closing->present($closing->close($fiscalYear, $this->actorId())));
    }

    public function reopen(Request $request, FiscalYear $fiscalYear, FiscalCloseService $closing): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'restore_period_status' => ['nullable', 'boolean'],
        ]);

        $year = $closing->reopen($fiscalYear, $data['reason'], $this->actorId(), (bool) ($data['restore_period_status'] ?? false));

        return $this->ok($closing->present($year) + ['previous_period_status' => $closing->lastPreviousPeriodStatus()]);
    }

    public function store(Request $request, AuditService $audit, FiscalYearSuggestionService $suggestions): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:50'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'confirm_custom_start' => ['nullable', 'boolean'],
        ]);

        // Missing values default from the configured fiscal year start.
        $suggestion = $suggestions->suggest();
        $data['starts_on'] = Carbon::parse($data['starts_on'] ?? $suggestion['starts_on'])->toDateString();
        $data['ends_on'] = isset($data['ends_on'])
            ? Carbon::parse($data['ends_on'])->toDateString()
            : $suggestions->endFor($data['starts_on']);
        if ($data['ends_on'] <= $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be after the start date.']);
        }
        $data['name'] = ($data['name'] ?? null) ?: $suggestions->nameFor($data['starts_on'], $data['ends_on']);

        $configured = $suggestions->configuredStart();
        if (substr($data['starts_on'], 5) !== $configured && ! ($data['confirm_custom_start'] ?? false)) {
            throw new DomainException("The start date does not match the configured fiscal year start ({$configured}). Send confirm_custom_start to create it anyway.");
        }

        $overlap = FiscalYear::query()
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->first();
        if ($overlap) {
            throw new DomainException("The dates overlap the existing fiscal year {$overlap->name}.");
        }

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

        if ($period->fiscalYear->isClosed()) {
            throw new DomainException('This period belongs to a closed fiscal year. Reopen the year instead.');
        }

        if ($data['status'] === 'closed') {
            $drafts = JournalEntry::query()->where('status', 'draft')
                ->whereDate('entry_date', '>=', $period->starts_on)
                ->whereDate('entry_date', '<=', $period->ends_on)
                ->count();

            if ($drafts > 0) {
                throw new DomainException("{$drafts} draft journal entries in this period must be posted or deleted before closing it (open the journal and delete the draft, or post it).");
            }
        }

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
