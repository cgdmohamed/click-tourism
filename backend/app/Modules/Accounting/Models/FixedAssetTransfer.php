<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Employee;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transfer of an asset between branches, cost centers or custodians.
 *
 * A change of branch posts two balanced journals through PostingService, one
 * per branch, at net book value via the inter-branch clearing accounts
 * (Odoo-style due-from / due-to). Cost-center or custodian changes within a
 * branch are operational only and post nothing. Posted history is never
 * rewritten; a wrong transfer is reversed (both journals).
 */
class FixedAssetTransfer extends Model
{
    use HasPublicId;

    protected $fillable = [
        'fixed_asset_id',
        'from_branch_id',
        'to_branch_id',
        'from_cost_center_id',
        'to_cost_center_id',
        'from_employee_id',
        'to_employee_id',
        'transfer_date',
        'reason',
        'source_journal_entry_id',
        'destination_journal_entry_id',
        'cost_minor',
        'accumulated_minor',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function sourceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'source_journal_entry_id');
    }

    public function destinationJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'destination_journal_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'from_cost_center_id');
    }

    public function toCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'to_cost_center_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'asset_id' => $this->asset?->public_id,
            'asset_code' => $this->asset?->code,
            'from_branch' => $this->fromBranch?->toApiArray(),
            'to_branch' => $this->toBranch?->toApiArray(),
            'from_cost_center' => $this->fromCostCenter?->toApiArray(),
            'to_cost_center' => $this->toCostCenter?->toApiArray(),
            'from_employee' => $this->fromEmployee ? [
                'id' => $this->fromEmployee->public_id,
                'name_ar' => $this->fromEmployee->name_ar,
                'name_en' => $this->fromEmployee->name_en,
            ] : null,
            'to_employee' => $this->toEmployee ? [
                'id' => $this->toEmployee->public_id,
                'name_ar' => $this->toEmployee->name_ar,
                'name_en' => $this->toEmployee->name_en,
            ] : null,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'reason' => $this->reason,
            'cost_minor' => (int) $this->cost_minor,
            'accumulated_minor' => (int) $this->accumulated_minor,
            'net_book_value_minor' => (int) $this->cost_minor - (int) $this->accumulated_minor,
            'source_journal' => $this->sourceJournalEntry ? ['id' => $this->sourceJournalEntry->public_id, 'number' => $this->sourceJournalEntry->number] : null,
            'destination_journal' => $this->destinationJournalEntry ? ['id' => $this->destinationJournalEntry->public_id, 'number' => $this->destinationJournalEntry->number] : null,
            'is_reversed' => $this->isReversed(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
