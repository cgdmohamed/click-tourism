<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RunReportExport;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Reports\Models\ExportJob;
use App\Modules\Reports\Services\ExportJobService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Background report exports (ADR-0025, GAP-RPT-02, AC-RPT-007). `POST /exports` queues a job that
 * re-runs the normal export route as the caller; the caller polls it and downloads the file only
 * when it succeeded. Every route is owner-only and needs `reports.export`.
 */
class ExportJobController extends ApiController
{
    public function index(Request $request, BranchScope $scope): JsonResponse
    {
        $user = $request->user();
        $rows = ExportJob::query()->where('user_id', $user->id)->where('branch_id', $scope->resolve($user)?->id)
            ->latest('id')->limit(20)->get()->map->toApiArray()->values();

        return $this->ok($rows);
    }

    public function store(Request $request, ExportJobService $exports, BranchScope $scope): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', 'string', Rule::in(config('exports.reports'))],
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'language' => ['nullable', 'in:ar,en,bilingual'],
            // The report's own filters, exactly as the synchronous export takes them (validated by that route when the job runs).
            'params' => ['nullable', 'array', 'max:20'],
            'params.*' => ['nullable', 'string', 'max:200'],
        ]);

        $user = $request->user();
        // The underlying route is re-checked when the job runs; this only stops a user from queueing a report they can never run.
        $params = collect($data['params'] ?? [])->except(['format', 'language'])->all();
        if (isset($data['language'])) {
            $params['language'] = $data['language'];
        }

        $job = $exports->queue($user, $scope->resolve($user), $data['report'], $data['format'], $params);
        RunReportExport::dispatch($job->id);

        return $this->created($job->refresh()->toApiArray());
    }

    public function show(Request $request, string $export): JsonResponse
    {
        $job = $this->own($request, $export);

        return $job === null ? ApiResponse::error('Export not found.', 'not_found', 404) : $this->ok($job->toApiArray());
    }

    public function download(Request $request, string $export, AuditService $audit): Response|JsonResponse
    {
        $job = $this->own($request, $export);
        if ($job === null) {
            return ApiResponse::error('Export not found.', 'not_found', 404);
        }
        if ($job->status !== 'succeeded' || $job->file_path === null) {
            return ApiResponse::error('The export is not ready.', 'export_not_ready', 409);
        }
        if ($job->expires_at !== null && $job->expires_at->isPast()) {
            return ApiResponse::error('The export expired. Run it again.', 'export_expired', 410);
        }
        $disk = Storage::disk(config('exports.disk'));
        if (! $disk->exists($job->file_path)) {
            return ApiResponse::error('The file is no longer available. Run the export again.', 'export_expired', 410);
        }

        $audit->log('export.downloaded', null, null, ['report' => $job->report, 'job' => $job->public_id], $request->user()->id, $job->branch_id);

        return response($disk->get($job->file_path), 200, [
            'Content-Type' => $job->mime,
            'Content-Disposition' => 'attachment; filename="'.addcslashes((string) $job->file_name, '"\\').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $export, ExportJobService $exports): JsonResponse
    {
        $job = $this->own($request, $export);
        if ($job === null) {
            return ApiResponse::error('Export not found.', 'not_found', 404);
        }
        if (in_array($job->status, ['queued', 'running'], true)) {
            return ApiResponse::error('A running export cannot be removed yet.', 'export_running', 409);
        }
        $exports->delete($job);

        return $this->ok(['deleted' => 1]);
    }

    private function own(Request $request, string $id): ?ExportJob
    {
        return ExportJob::query()->where('user_id', $request->user()->id)->where('public_id', $id)->first();
    }
}
