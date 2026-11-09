<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceBatch extends Model
{
    use HasPublicId;

    protected $fillable = [
        'branch_id',
        'as_of_date',
        'memo',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return ['as_of_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(OpeningBalanceLine::class);
    }

    public function entries()
    {
        return $this->belongsToMany(JournalEntry::class, 'opening_balance_entries', 'opening_balance_batch_id', 'journal_entry_id')
            ->withPivot('currency_code');
    }

    public function toApiArray(bool $withLines = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'branch_id' => $this->branch?->public_id,
            'as_of_date' => $this->as_of_date?->toDateString(),
            'memo' => $this->memo,
            'status' => $this->status,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
        ];

        if ($withLines) {
            $payload['lines'] = $this->lines->map(fn (OpeningBalanceLine $line) => $line->toApiArray())->values()->all();
        }

        return $payload;
    }
}
