<?php

namespace App\Modules\Reports\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** A report e-mailed on a schedule (ADR-0026). It runs as the user who created it. */
class ReportSchedule extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'branch_id', 'name', 'report', 'format', 'params', 'period', 'frequency', 'day', 'recipients',
        'is_active', 'next_run_at', 'last_run_at', 'last_status', 'last_error', 'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array', 'recipients' => 'array', 'is_active' => 'boolean',
            'next_run_at' => 'datetime', 'last_run_at' => 'datetime',
        ];
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
            'name' => $this->name,
            'report' => $this->report,
            'format' => $this->format,
            'params' => $this->params ?? [],
            'period' => $this->period,
            'frequency' => $this->frequency,
            'day' => $this->day,
            'recipients' => $this->recipients ?? [],
            'is_active' => $this->is_active,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_status' => $this->last_status,
            'last_error' => $this->last_error,
        ];
    }
}
