<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A depreciation run for one branch and period (Prompt 25).
 *
 * A draft run is a preview. Posting writes exactly one journal through
 * PostingService; the per-asset/period unique item is the duplicate guard.
 */
class FixedAssetDepreciationRun extends Model
{
    use HasPublicId;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'number',
        'branch_id',
        'period',
        'run_date',
        'status',
        'assets_count',
        'total_amount_minor',
        'journal_entry_id',
        'failure_reason',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'assets_count' => 'integer',
            'total_amount_minor' => 'integer',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationItem::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(bool $withItems = true): array
    {
        $payload = [
            'id' => $this->public_id,
            'number' => $this->number,
            'branch_id' => $this->branch?->public_id,
            'branch' => $this->branch?->toApiArray(),
            'period' => $this->period,
            'run_date' => $this->run_date?->toDateString(),
            'status' => $this->status,
            'assets_count' => $this->assets_count,
            'total_amount_minor' => $this->total_amount_minor,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($withItems) {
            $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
            $payload['items'] = $items->map(fn (FixedAssetDepreciationItem $item) => $item->toApiArray())->values()->all();
        }

        return $payload;
    }
}
