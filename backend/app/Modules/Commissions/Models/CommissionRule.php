<?php

namespace App\Modules\Commissions\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a commission rule. Approved versions are immutable; changing
 * a rule creates the next version (same code) that supersedes it from its own
 * effective date (DEC-013: most specific wins, then the latest effective date).
 */
class CommissionRule extends Model
{
    use HasPublicId;

    public const BENEFICIARIES = ['employee', 'agent', 'branch'];

    public const BASES = ['percent_revenue', 'percent_margin', 'fixed'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'retired'];

    protected $fillable = [
        'code', 'version', 'name_ar', 'name_en', 'status', 'beneficiary_type', 'beneficiary_id',
        'basis', 'rate_bp', 'fixed_minor', 'branch_id', 'service_type', 'allow_cross_branch',
        'effective_from', 'effective_to', 'supersedes_id', 'created_by', 'submitted_by',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rate_bp' => 'integer',
            'fixed_minor' => 'integer',
            'beneficiary_id' => 'integer',
            'allow_cross_branch' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** Specificity weight used by the precedence resolver (DEC-013). */
    public function specificity(): int
    {
        return ($this->service_type !== null ? 4 : 0)
            + ($this->branch_id !== null ? 2 : 0)
            + ($this->beneficiary_id !== null ? 1 : 0);
    }

    /** @return array{type: string, id: ?string, name_ar: ?string, name_en: ?string}|null */
    public function beneficiaryArray(): ?array
    {
        return self::describeBeneficiary($this->beneficiary_type, $this->beneficiary_id);
    }

    /** @return array{type: string, id: ?string, name_ar: ?string, name_en: ?string}|null */
    public static function describeBeneficiary(string $type, ?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $record = match ($type) {
            'employee' => Employee::query()->find($id),
            'agent' => Supplier::query()->find($id),
            'branch' => Branch::query()->find($id),
            default => null,
        };

        return $record ? [
            'type' => $type,
            'id' => $record->public_id,
            'name_ar' => $record->name_ar,
            'name_en' => $record->name_en,
        ] : null;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'version' => $this->version,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'status' => $this->status,
            'beneficiary_type' => $this->beneficiary_type,
            'beneficiary' => $this->beneficiaryArray(),
            'basis' => $this->basis,
            'rate_bp' => $this->rate_bp,
            'fixed_minor' => $this->fixed_minor,
            'branch_id' => $this->branch?->public_id,
            'service_type' => $this->service_type,
            'allow_cross_branch' => (bool) $this->allow_cross_branch,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'supersedes_id' => $this->supersedes?->public_id,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
