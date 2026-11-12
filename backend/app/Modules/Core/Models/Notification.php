<?php

namespace App\Modules\Core\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification addressed to one user (GAP-CORE-01).
 *
 * `type` is the stable contract (`lead.reassigned`, `invoice.approved`, ...);
 * the UI localises by type and falls back to `title`/`body` for unknown types.
 */
class Notification extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id',
        'branch_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function markRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        return $this->update(['read_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?? [],
            'branch_id' => $this->branch?->public_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
