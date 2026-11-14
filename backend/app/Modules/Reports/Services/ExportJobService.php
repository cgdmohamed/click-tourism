<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Reports\Models\ExportJob;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Background report exports (ADR-0025, AC-RPT-007). A job replays the normal export route as the
 * user with a higher row cap; the file is stored only when that route answered with a file, so a
 * partial or error body is never offered for download.
 */
class ExportJobService
{
    private const MIME_EXTENSIONS = [
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** @param  array<string, mixed>  $params */
    public function queue(User $user, ?Branch $branch, string $report, string $format, array $params): ExportJob
    {
        $job = ExportJob::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch?->id,
            'report' => $report,
            'format' => $format,
            'params' => $params,
            'status' => 'queued',
        ]);
        $this->audit->log('export.queued', null, null, ['report' => $report, 'format' => $format, 'job' => $job->public_id], $user->id, $branch?->id);

        return $job;
    }

    public function run(ExportJob $job): void
    {
        if ($job->status !== 'queued') {
            return;
        }
        $job->update(['status' => 'running', 'started_at' => now()]);

        $user = User::query()->find($job->user_id);
        $branch = $job->branch_id ? Branch::query()->find($job->branch_id) : null;
        if ($user === null || ! $user->is_active) {
            $this->fail($job, 'The requesting user is not available.');

            return;
        }

        $query = array_merge($job->params, ['format' => $job->format]);
        $response = $this->dispatch($user, '/reports/'.$job->report.'/export', $query, $branch?->public_id);
        $status = $response->getStatusCode();
        $body = (string) ($response instanceof BinaryFileResponse ? file_get_contents($response->getFile()->getPathname()) : $response->getContent());
        $mime = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));

        if ($status !== 200 || ! isset(self::MIME_EXTENSIONS[$mime]) || $body === '') {
            $decoded = json_decode($body, true);
            $message = $status === 403 ? 'Not allowed to export this report.' : (is_array($decoded) ? $this->firstMessage($decoded) : 'The export could not be produced.');
            $this->fail($job, $status >= 500 ? 'The export could not be produced.' : $message);

            return;
        }

        $disk = Storage::disk(config('exports.disk'));
        $path = 'exports/'.$job->public_id.'/'.Str::random(24).'.'.self::MIME_EXTENSIONS[$mime];
        if (! $disk->put($path, $body)) {
            $this->fail($job, 'The file could not be stored.');

            return;
        }

        $name = $this->fileName($response, $job, self::MIME_EXTENSIONS[$mime]);
        $job->update([
            'status' => 'succeeded', 'file_path' => $path, 'file_name' => $name, 'mime' => $mime, 'size_bytes' => strlen($body),
            'finished_at' => now(), 'expires_at' => now()->addDays((int) config('exports.ttl_days')),
        ]);
        $this->audit->log('export.completed', null, null, ['report' => $job->report, 'format' => $job->format, 'job' => $job->public_id, 'bytes' => strlen($body)], $user->id, $job->branch_id);
    }

    public function fail(ExportJob $job, string $message): void
    {
        $job->update(['status' => 'failed', 'error_message' => mb_substr($message, 0, 400), 'finished_at' => now()]);
        $this->audit->log('export.failed', null, null, ['report' => $job->report, 'job' => $job->public_id], $job->user_id, $job->branch_id);
    }

    public function delete(ExportJob $job): void
    {
        if ($job->file_path) {
            Storage::disk(config('exports.disk'))->deleteDirectory(dirname($job->file_path));
        }
        $job->delete();
    }

    /** Deletes finished files past their expiry and old failed rows. */
    public function prune(): int
    {
        $count = 0;
        $expired = ExportJob::query()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere(fn ($q) => $q->where('status', 'failed')->where('finished_at', '<', now()->subDays((int) config('exports.ttl_days')))))->get();
        foreach ($expired as $job) {
            $this->delete($job);
            $count++;
        }

        return $count;
    }

    private function firstMessage(array $body): string
    {
        $errors = $body['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                    return $messages[0];
                }
            }
        }

        return is_string($body['message'] ?? null) ? $body['message'] : 'The export could not be produced.';
    }

    private function fileName(Response $response, ExportJob $job, string $extension): string
    {
        if (preg_match('/filename="?([^";]+)"?/i', (string) $response->headers->get('Content-Disposition'), $m) === 1) {
            return mb_substr(basename($m[1]), 0, 150);
        }

        return $job->report.'.'.$extension;
    }

    /** Same-process request to the normal export route, as the user, with the background row cap. */
    private function dispatch(User $user, string $path, array $query, ?string $branchPublicId): Response
    {
        $request = Request::create('/api/v1'.$path, 'GET', $query);
        $request->headers->set('Accept', '*/*');
        if ($branchPublicId !== null) {
            $request->headers->set('X-Branch-Id', $branchPublicId);
        }
        // Set here, never read from the client: only a job gets the higher row cap.
        $request->attributes->set('export_job', true);
        $request->setUserResolver(fn () => $user);
        Auth::guard('sanctum')->setUser($user);

        $original = app('request');
        app()->instance('request', $request);

        try {
            return app('router')->dispatch($request);
        } catch (\Throwable $e) {
            $handler = app(ExceptionHandler::class);
            $handler->report($e);

            return $handler->render($request, $e);
        } finally {
            app()->instance('request', $original);
        }
    }
}
