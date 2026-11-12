<?php

namespace App\Modules\Imports\Services;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\Imports\Definitions\ImportDefinition;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Support\Caster;
use App\Modules\Imports\Support\HeaderMap;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportFileException;
use App\Modules\Imports\Support\ImportRun;
use App\Modules\Imports\Support\Sanitizer;
use App\Modules\Imports\Support\SpreadsheetReader;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Executes one import job (dry run or commit). Runs inside the request for
 * small files and on the queue worker for large ones.
 */
class ImportRunner
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
    ) {}

    public function run(int $jobId): void
    {
        // Atomic claim: a retried / duplicated queue message becomes a no-op.
        $claimed = ImportJob::query()->where('id', $jobId)->where('status', ImportJob::STATUS_QUEUED)
            ->update(['status' => ImportJob::STATUS_RUNNING, 'started_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $job = ImportJob::query()->with(['creator', 'branch'])->findOrFail($jobId);

        try {
            $this->execute($job);
        } catch (ImportFileException $e) {
            $this->fail($job, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($job, 'internal_error', 'The import could not be completed. Nothing was lost; contact an administrator if it keeps failing.');
        }
    }

    /** Mark a job failed (also used by the queue's failed() hook). */
    public function fail(ImportJob $job, string $code, string $message): void
    {
        $job->refresh();
        if ($job->isFinal()) {
            return;
        }

        $this->discardSource($job);
        $job->update([
            'status' => ImportJob::STATUS_FAILED,
            'failure_code' => $code,
            'failure_message' => mb_substr($message, 0, 1000),
            'file_path' => null,
            'finished_at' => now(),
        ]);
        $this->audit->log('import.failed', $job, null, ['entity' => $job->entity, 'code' => $code], $job->created_by);
    }

    private function execute(ImportJob $job): void
    {
        $definition = $this->registry->get($job->entity);
        $user = $job->creator;

        if (! $user || ! $user->is_active) {
            throw new ImportFileException('forbidden', 'The user who started this import is no longer active.');
        }
        foreach ($definition->permissions($job->duplicate_policy) as $permission) {
            if (! $user->can($permission)) {
                throw new ImportFileException('forbidden', 'The user who started this import no longer has the required permission.');
            }
        }

        $allowed = $this->branchScope->allowedBranchIds($user);
        if ($job->branch_id !== null && ! in_array($job->branch_id, $allowed, true)) {
            throw new ImportFileException('forbidden', 'The user who started this import is no longer assigned to its branch.');
        }

        $apply = $job->mode === ImportJob::MODE_COMMIT;
        $ctx = new ImportContext(
            branch: $job->branch,
            userId: $job->created_by,
            allowedBranchIds: $allowed,
            options: (array) ($job->options ?? []),
            policy: $job->duplicate_policy,
            apply: $apply,
            jobPublicId: $job->public_id,
            baseCurrency: $this->settings->baseCurrency(),
            caster: new Caster,
        );
        $run = new ImportRun($ctx, (int) config('imports.preview_rows'));

        $local = $this->localCopy($job);
        try {
            $this->process($job, $definition, $run, $local);
        } finally {
            @unlink($local);
        }

        $this->finish($job, $definition, $run);
    }

    private function process(ImportJob $job, ImportDefinition $definition, ImportRun $run, string $path): void
    {
        $iterator = SpreadsheetReader::fromConfig()->rows($path, $job->file_format);
        if (! $iterator->valid()) {
            throw new ImportFileException('empty_file', 'The file contains no rows.');
        }

        $header = HeaderMap::build($definition->columns(), $iterator->current()['cells']);
        $iterator->next();
        $job->warnings = $header['unknown'] === [] ? null : [['code' => 'unknown_columns', 'columns' => $header['unknown']]];

        $atomic = $definition->isAtomic() || $job->on_error === 'abort';
        $wrap = $run->ctx->apply && $atomic;

        if ($wrap) {
            DB::beginTransaction();
        }
        try {
            $definition->process($run, $this->dataRows($iterator, $header['map'], $run));
        } catch (Throwable $e) {
            if ($wrap) {
                DB::rollBack();
            }
            throw $e;
        }

        if ($atomic && $run->errors !== []) {
            if ($wrap) {
                DB::rollBack();
            }
            $run->resetApplied();
        } elseif ($wrap) {
            DB::commit();
        }
    }

    /**
     * Turns raw spreadsheet rows into keyed, trimmed data and rejects any row
     * carrying a formula-looking cell before a definition sees it.
     *
     * @param  array<int, string>  $map  column index => template key
     * @return Generator<int, array{row: int, data: array<string, string>}>
     */
    private function dataRows(Generator $iterator, array $map, ImportRun $run): Generator
    {
        while ($iterator->valid()) {
            ['row' => $row, 'cells' => $cells] = $iterator->current();
            $iterator->next();
            $run->total++;

            $data = [];
            $unsafe = [];
            foreach ($map as $index => $key) {
                $raw = $cells[$index] ?? '';
                $data[$key] = trim($raw);
                if (Sanitizer::isFormula($raw)) {
                    $unsafe[] = $key;
                }
            }

            if ($unsafe !== []) {
                foreach ($unsafe as $key) {
                    $run->error($row, 'formula_detected', "Column '{$key}' starts with = + - or @ and could run as a spreadsheet formula.", $key, [], $data);
                }

                continue;
            }

            yield ['row' => $row, 'data' => $data];
        }
    }

    private function finish(ImportJob $job, ImportDefinition $definition, ImportRun $run): void
    {
        $errorRows = $run->errorRowCount();
        $atomic = $definition->isAtomic() || $job->on_error === 'abort';
        $applied = $run->created + $run->updated;

        $attributes = [
            'total_rows' => $run->total,
            'created_count' => $run->created,
            'updated_count' => $run->updated,
            'skipped_count' => $run->skipped,
            'error_count' => $errorRows,
            'preview' => $run->preview,
            'errors' => array_slice($run->errors, 0, (int) config('imports.inline_errors')),
            'warnings' => $job->warnings,
            'finished_at' => now(),
        ];

        if ($errorRows > 0) {
            $attributes['error_report_path'] = $this->writeReport($job, $definition, $run);
        }

        if ($run->result !== []) {
            $attributes['options'] = array_merge((array) $job->options, ['result' => $run->result]);
        }

        if ($job->mode === ImportJob::MODE_DRY_RUN) {
            $job->update($attributes + ['status' => ImportJob::STATUS_PREVIEWED]);
            $this->audit->log('import.previewed', $job, null, $this->auditPayload($job), $job->created_by);

            return;
        }

        if ($errorRows === 0) {
            $status = ImportJob::STATUS_COMPLETED;
        } elseif (! $atomic && $applied > 0) {
            $status = ImportJob::STATUS_PARTIAL;
        } else {
            $status = ImportJob::STATUS_FAILED;
            $attributes['failure_code'] = $job->on_error === 'abort' && ! $definition->isAtomic() ? 'aborted_on_errors' : 'validation_failed';
            $attributes['failure_message'] = $atomic
                ? 'The file has errors, so nothing was imported. Fix the rows in the error report and upload it again.'
                : 'Every row failed validation; nothing was imported.';
        }

        $this->discardSource($job);
        $job->update($attributes + ['status' => $status, 'file_path' => null]);
        $this->audit->log('import.'.$status, $job, null, $this->auditPayload($job), $job->created_by);
    }

    /** @return array<string, mixed> */
    private function auditPayload(ImportJob $job): array
    {
        return [
            'entity' => $job->entity,
            'status' => $job->status,
            'file_sha256' => $job->file_sha256,
            'total_rows' => $job->total_rows,
            'created' => $job->created_count,
            'updated' => $job->updated_count,
            'skipped' => $job->skipped_count,
            'errors' => $job->error_count,
        ];
    }

    private function writeReport(ImportJob $job, ImportDefinition $definition, ImportRun $run): string
    {
        $columns = $definition->columns();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_merge(['row', 'field', 'code', 'message'], array_map(fn ($c) => $c->key, $columns)), ',', '"', '');

        foreach ($run->errors as $error) {
            $raw = $run->rawErrorRows[$error['row']] ?? [];
            $line = [$error['row'] ?: '', $error['field'] ?? '', $error['code'], $error['message']];
            foreach ($columns as $column) {
                $line[] = $raw[$column->key] ?? '';
            }
            // Row data came from the user's file: neutralise it for Excel.
            fputcsv($handle, array_map(Sanitizer::forExport(...), $line), ',', '"', '');
        }

        rewind($handle);
        $csv = "\xEF\xBB\xBF".stream_get_contents($handle);
        fclose($handle);

        $path = "imports/{$job->public_id}/errors.csv";
        Storage::disk(config('imports.disk'))->put($path, $csv);

        return $path;
    }

    private function localCopy(ImportJob $job): string
    {
        $stream = $job->file_path ? Storage::disk(config('imports.disk'))->readStream($job->file_path) : null;
        if (! is_resource($stream)) {
            throw new ImportFileException('source_missing', 'The uploaded file is no longer available. Upload it again.');
        }

        $local = tempnam(sys_get_temp_dir(), 'imp');
        $out = fopen($local, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $local;
    }

    public function discardSource(ImportJob $job): void
    {
        if ($job->file_path) {
            Storage::disk(config('imports.disk'))->delete($job->file_path);
        }
    }
}
