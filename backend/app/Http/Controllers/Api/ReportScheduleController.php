<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Services\ReportScheduleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scheduled report e-mail (ADR-0026, GAP-RPT-06). Owner-only: a schedule runs as the user who
 * created it, with their permissions and branch, and mails the file to the listed addresses
 * through the SMTP settings of the server environment.
 */
class ReportScheduleController extends ApiController
{
    public function index(Request $request, BranchScope $scope): JsonResponse
    {
        $user = $request->user();
        $rows = ReportSchedule::query()->where('user_id', $user->id)->where('branch_id', $scope->resolve($user)?->id)
            ->orderBy('id')->get()->map->toApiArray()->values();

        return $this->ok($rows);
    }

    public function store(Request $request, ReportScheduleService $service, AuditService $audit, BranchScope $scope): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $schedule = ReportSchedule::query()->create($data + [
            'is_active' => true,
            'consecutive_failures' => 0,
            'user_id' => $user->id,
            'branch_id' => $scope->resolve($user)?->id,
            'next_run_at' => $service->nextRunAt($data['frequency'], $data['day'] ?? null),
        ]);
        $audit->log('report_schedule.created', null, null, ['schedule' => $schedule->public_id, 'report' => $schedule->report, 'recipients' => count($schedule->recipients)], $user->id, $schedule->branch_id);

        return $this->created($schedule->toApiArray());
    }

    public function update(Request $request, string $schedule, ReportScheduleService $service, AuditService $audit): JsonResponse
    {
        $row = $this->own($request, $schedule);
        if ($row === null) {
            return ApiResponse::error('Schedule not found.', 'not_found', 404);
        }

        $data = $this->validated($request, partial: true);
        $row->fill($data);
        if (array_key_exists('is_active', $data) && $data['is_active'] === true) {
            $row->consecutive_failures = 0;
        }
        if (isset($data['frequency']) || array_key_exists('day', $data)) {
            $row->next_run_at = $service->nextRunAt($row->frequency, $row->day);
        }
        $row->save();
        $audit->log('report_schedule.updated', null, null, ['schedule' => $row->public_id], $request->user()->id, $row->branch_id);

        return $this->ok($row->toApiArray());
    }

    public function destroy(Request $request, string $schedule, AuditService $audit): JsonResponse
    {
        $row = $this->own($request, $schedule);
        if ($row === null) {
            return ApiResponse::error('Schedule not found.', 'not_found', 404);
        }
        $audit->log('report_schedule.deleted', null, null, ['schedule' => $row->public_id], $request->user()->id, $row->branch_id);
        $row->delete();

        return $this->ok(['deleted' => 1]);
    }

    /** Sends the report now (same path as a scheduled run); the result is in `last_status`. */
    public function run(Request $request, string $schedule, ReportScheduleService $service): JsonResponse
    {
        $row = $this->own($request, $schedule);
        if ($row === null) {
            return ApiResponse::error('Schedule not found.', 'not_found', 404);
        }
        $service->run($row);

        return $this->ok($row->refresh()->toApiArray());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'report' => [$required, 'string', Rule::in(config('exports.reports'))],
            'format' => [$required, 'in:csv,xlsx,pdf'],
            'frequency' => [$required, 'in:daily,weekly,monthly'],
            'day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'period' => ['nullable', 'string', Rule::in(ReportScheduleService::PERIODS)],
            'params' => ['nullable', 'array', 'max:20'],
            'params.*' => ['nullable', 'string', 'max:200'],
            'recipients' => [$required, 'array', 'min:1', 'max:10'],
            'recipients.*' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['frequency']) && $data['frequency'] === 'weekly' && isset($data['day']) && $data['day'] > 7) {
            abort(422, 'The weekday must be 1 (Monday) to 7 (Sunday).');
        }
        if (isset($data['params'])) {
            $data['params'] = collect($data['params'])->except(['format', 'language'])->all();
        } elseif (! $partial) {
            $data['params'] = [];
        }
        if (isset($data['recipients'])) {
            $data['recipients'] = array_values(array_map('strtolower', $data['recipients']));
        }

        return $data;
    }

    private function own(Request $request, string $id): ?ReportSchedule
    {
        return ReportSchedule::query()->where('user_id', $request->user()->id)->where('public_id', $id)->first();
    }
}
