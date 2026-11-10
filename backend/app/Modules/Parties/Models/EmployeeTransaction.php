<?php

namespace App\Modules\Parties\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement in an employee's financial subledger (advance, custody, commission,
 * adjustment). Every transaction optionally ties to a posted journal entry so the
 * subledger reconciles to the GL control accounts.
 */
class EmployeeTransaction extends Model
{
    use HasPublicId;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'type',
        'reference',
        'journal_entry_id',
        'source_id',
        'source_type',
        'transaction_date',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'amount_minor',
        'balance_minor',
        'memo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'fx_rate_date' => 'date',
            'fx_rate' => 'decimal:10',
            'amount_minor' => 'integer',
            'balance_minor' => 'integer',
        ];
    }

    public const TYPE_ADVANCE_REQUEST = 'advance_request';

    public const TYPE_ADVANCE_DISBURSED = 'advance_disbursed';

    public const TYPE_ADVANCE_SETTLED = 'advance_settled';

    public const TYPE_CUSTODY_ISSUED = 'custody_issued';

    public const TYPE_CUSTODY_CLEARED = 'custody_cleared';

    public const TYPE_CUSTODY_RETURNED = 'custody_returned';

    public const TYPE_COMMISSION_PAID = 'commission_paid';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public static $types = [
        self::TYPE_ADVANCE_REQUEST,
        self::TYPE_ADVANCE_DISBURSED,
        self::TYPE_ADVANCE_SETTLED,
        self::TYPE_CUSTODY_ISSUED,
        self::TYPE_CUSTODY_CLEARED,
        self::TYPE_CUSTODY_RETURNED,
        self::TYPE_COMMISSION_PAID,
        self::TYPE_ADJUSTMENT,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'employee_id' => $this->employee->public_id,
            'branch_id' => $this->branch?->public_id,
            'type' => $this->type,
            'reference' => $this->reference,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'transaction_date' => $this->transaction_date->toDateString(),
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'amount_minor' => $this->amount_minor,
            'balance_minor' => $this->balance_minor,
            'memo' => $this->memo,
            'created_by' => $this->created_by,
        ];
    }
}
