<?php

namespace App\Modules\Imports\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Imports\Definitions\ImportDefinition;
use App\Modules\Imports\Jobs\ProcessImportJob;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Support\HeaderMap;
use App\Modules\Imports\Support\ImportFileException;
use App\Modules\Imports\Support\SpreadsheetReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportService
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    /** @return list<string> */
    public static function policies(): array
    {
        return ['skip', 'update', 'fail'];
    }

    /** Can this user run imports of this entity (under the given duplicate policy)? */
    public function canRun(User $user, ImportDefinition $definition, ?string $policy = null): bool
    {
        foreach ($definition->permissions($policy ?? $definition->defaultPolicy()) as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the upload, store it privately and start the job. A dry run
     * (default) ends in `previewed`; a direct commit goes `queued -> ...`.
     *
     * @param  array{duplicate_policy?: ?string, on_error?: ?string, dry_run?: bool, template_version?: ?int, options?: array<string, mixed>}  $input
     */
    public function create(User $user, Branch $branch, string $entity, UploadedFile $file, array $input): ImportJob
    {
        $definition = $this->registry->get($entity);

        $policy = $input['duplicate_policy'] ?? $definition->defaultPolicy();
        if (! $definition->supportsDuplicatePolicy()) {
            $policy = $definition->defaultPolicy();
        }
        if (! in_array($policy, self::policies(), true)) {
            throw new ImportFileException('invalid_policy', 'duplicate_policy must be skip, update or fail.');
        }

        if (! $this->canRun($user, $definition, $policy)) {
            throw new AuthorizationException('You are not allowed to import this data.');
        }

        if (isset($input['template_version']) && (int) $input['template_version'] !== $definition->version()) {
            throw new ImportFileException(
                'unsupported_template_version',
                "Template version {$input['template_version']} is not supported; download the current version ({$definition->version()}).",
                ['supported' => $definition->version()],
            );
        }

        $options = $this->validatedOptions($definition, $input['options'] ?? []);
        $path = (string) $file->getRealPath();
        $this->assertWithinLimits($file, $path);
        $format = SpreadsheetReader::detectFormat($path, $file->getClientOriginalName());

        // Full structural scan before anything is stored: encoding, row and
        // column limits and the header all fail fast with a clear 422.
        $this->scan($definition, $path, $format);

        $job = ImportJob::query()->create([
            'entity' => $definition->entity(),
            'template_version' => $definition->version(),
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'status' => ImportJob::STATUS_QUEUED,
            'mode' => ($input['dry_run'] ?? true) ? ImportJob::MODE_DRY_RUN : ImportJob::MODE_COMMIT,
            'duplicate_policy' => $policy,
            'on_error' => $input['on_error'] ?? 'skip',
            'options' => $options === [] ? null : $options,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'file_format' => $format,
            'file_size' => (int) filesize($path),
            'file_sha256' => hash_file('sha256', $path),
        ]);

        $storedPath = "imports/{$job->public_id}/source.{$format}";
        Storage::disk(config('imports.disk'))->put($storedPath, (string) file_get_contents($path));
        $job->update(['file_path' => $storedPath]);

        $this->audit->log('import.uploaded', $job, null, [
            'entity' => $job->entity,
            'mode' => $job->mode,
            'file_name' => $job->original_name,
            'file_sha256' => $job->file_sha256,
            'file_size' => $job->file_size,
            'duplicate_policy' => $policy,
        ], $user->id);

        $this->dispatch($job);

        return $job->refresh()->load(['branch', 'creator']);
    }

    /** previewed -> queued (commit). Safe to call twice: the second call conflicts. */
    public function confirm(User $user, ImportJob $job): ImportJob
    {
        $definition = $this->registry->get($job->entity);
        if (! $this->canRun($user, $definition, $job->duplicate_policy)) {
            throw new AuthorizationException('You are not allowed to import this data.');
        }

        $moved = ImportJob::query()->where('id', $job->id)->where('status', ImportJob::STATUS_PREVIEWED)
            ->update(['status' => ImportJob::STATUS_QUEUED, 'mode' => ImportJob::MODE_COMMIT, 'finished_at' => null]);
        if ($moved === 0) {
            throw new ImportFileException('invalid_state', 'Only a previewed import can be confirmed.', [], 409);
        }

        $job->refresh();
        $this->audit->log('import.confirmed', $job, null, ['entity' => $job->entity, 'file_sha256' => $job->file_sha256, 'confirmed_by' => $user->id], $user->id);

        $this->dispatch($job);

        return $job->refresh()->load(['branch', 'creator']);
    }

    /** Throw away a preview that will not be confirmed. */
    public function discard(User $user, ImportJob $job): void
    {
        if ($job->status !== ImportJob::STATUS_PREVIEWED) {
            throw new ImportFileException('invalid_state', 'Only a previewed import can be discarded.', [], 409);
        }

        $this->registry->get($job->entity);
        $disk = Storage::disk(config('imports.disk'));
        foreach ([$job->file_path, $job->error_report_path] as $path) {
            if ($path) {
                $disk->delete($path);
            }
        }
        $this->audit->log('import.discarded', $job, ['status' => $job->status], null, $user->id);
        $job->delete();
    }

    public function dispatch(ImportJob $job): void
    {
        // Small files finish inside the request; big ones go to the worker.
        if ($job->file_size <= (int) config('imports.sync_max_bytes')) {
            ProcessImportJob::dispatchSync($job->id);
        } else {
            ProcessImportJob::dispatch($job->id);
        }
    }

    private function assertWithinLimits(UploadedFile $file, string $path): void
    {
        if (! $file->isValid() || $path === '') {
            throw new ImportFileException('upload_failed', 'The file could not be uploaded.');
        }

        $size = (int) filesize($path);
        $max = (int) config('imports.max_file_bytes');
        if ($size === 0) {
            throw new ImportFileException('empty_file', 'The file is empty.');
        }
        if ($size > $max) {
            throw new ImportFileException('file_too_large', 'The file is larger than '.round($max / 1048576, 1).' MB.', ['max_bytes' => $max], 413);
        }
    }

    private function scan(ImportDefinition $definition, string $path, string $format): void
    {
        $rows = SpreadsheetReader::fromConfig()->rows($path, $format);
        if (! $rows->valid()) {
            throw new ImportFileException('empty_file', 'The file contains no rows.');
        }

        HeaderMap::build($definition->columns(), $rows->current()['cells']);

        $count = 0;
        $rows->next();
        while ($rows->valid()) {
            $count++;
            $rows->next();
        }
        if ($count === 0) {
            throw new ImportFileException('no_data_rows', 'The file has a header but no data rows.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validatedOptions(ImportDefinition $definition, array $input): array
    {
        $rules = [];
        foreach ($definition->options() as $key => $option) {
            $rules[$key] = $option['rules'];
        }
        if ($rules === []) {
            return [];
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return array_intersect_key($validator->validated(), $rules);
    }
}
