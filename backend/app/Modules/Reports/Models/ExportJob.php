<?php

namespace App\Modules\Reports\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'branch_id', 'report', 'format', 'params', 'status', 'file_path', 'file_name', 'mime',
        'size_bytes', 'error_message', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['params' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'report' => $this->report,
            'format' => $this->format,
            'status' => $this->status,
            'file_name' => $this->status === 'succeeded' ? $this->file_name : null,
            'size_bytes' => $this->status === 'succeeded' ? $this->size_bytes : null,
            'error' => $this->status === 'failed' ? $this->error_message : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->status === 'succeeded' ? $this->expires_at?->toIso8601String() : null,
        ];
    }
}
