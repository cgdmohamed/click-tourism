<?php

namespace App\Modules\Accounting\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allocation of a posted journal line to one or more cost centers.
 *
 * Exact allocations carry a minor-unit amount; percent allocations carry a
 * percentage. The sum of allocations for a line must equal the line's posted
 * amount (debit or credit, whichever is non-zero).
 */
class CostCenterAllocation extends Model
{
    use HasPublicId;

    protected $fillable = [
        'journal_line_id',
        'cost_center_id',
        'allocation_type',
        'amount_minor',
        'percent',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'percent' => 'decimal:2',
        ];
    }

    public const TYPE_EXACT = 'exact';

    public const TYPE_PERCENT = 'percent';

    public static $types = [self::TYPE_EXACT, self::TYPE_PERCENT];

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'journal_line_id' => $this->journalLine?->id,
            'cost_center' => $this->costCenter?->toApiArray(),
            'allocation_type' => $this->allocation_type,
            'amount_minor' => $this->amount_minor,
            'percent' => $this->percent ? (string) $this->percent : null,
        ];
    }
}
