<?php

namespace App\Modules\Accounting\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cost center — an analytical dimension on posted journal lines, NOT a GL
 * account. Hierarchical tree with cycle protection; archived centers keep
 * historical attribution and are never deleted while they carry allocations.
 */
class CostCenter extends Model
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'parent_id',
        'status',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public static $statuses = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CostCenterAllocation::class);
    }

    public function toApiArray(bool $withChildren = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'parent_id' => $this->parent?->public_id,
            'status' => $this->status,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
        ];

        if ($withChildren) {
            $payload['children'] = $this->children->map(fn (CostCenter $child) => $child->toApiArray(true))->values()->all();
        }

        return $payload;
    }

    /**
     * Detect whether adding `parentId` as a parent of this center would create
     * a cycle (a center cannot be its own ancestor).
     */
    public function wouldCreateCycle(?int $parentId): bool
    {
        if ($parentId === null || $parentId === $this->id) {
            return $parentId === $this->id;
        }

        $visited = [];
        $center = self::query()->find($parentId);

        while ($center && ! in_array($center->id, $visited, true)) {
            if ($center->id === $this->id) {
                return true;
            }
            $visited[] = $center->id;
            $center = $center->parent;
        }

        return false;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_active', true);
    }
}
