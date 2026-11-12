<?php

namespace App\Modules\Imports\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use HasPublicId;

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_COMMIT = 'commit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'preview' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'file_size' => 'integer',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'error_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_PARTIAL, self::STATUS_FAILED], true);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'entity' => $this->entity,
            'template_version' => (int) $this->template_version,
            'status' => $this->status,
            'mode' => $this->mode,
            'duplicate_policy' => $this->duplicate_policy,
            'on_error' => $this->on_error,
            'options' => $this->options ?? (object) [],
            'original_name' => $this->original_name,
            'file_format' => $this->file_format,
            'file_size' => $this->file_size,
            'branch_id' => $this->branch?->public_id,
            'created_by' => $this->creator?->name,
            'total_rows' => $this->total_rows,
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'error_count' => $this->error_count,
            'preview' => $this->preview ?? [],
            'errors' => $this->errors ?? [],
            'warnings' => $this->warnings ?? [],
            'has_error_report' => $this->error_report_path !== null,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
