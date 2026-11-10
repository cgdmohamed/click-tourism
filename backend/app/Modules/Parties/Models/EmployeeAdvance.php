<?php

namespace App\Modules\Parties\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee cash advance. Lifecycle: requested -> approved -> disbursed -> settled
 * (or cancelled). Disbursement posts DR employee advance / CR cash; settlement
 * posts CR employee advance / DR expense or cash. The outstanding balance lives
 * in the employee subledger.
 */
class EmployeeAdvance extends Model
{
    use HasPublicId;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'amount_minor',
        'outstanding_minor',
        'status',
        'disbursement_journal_entry_id',
        'cash_account_id',
        'request_date',
        'disbursed_date',
        'settled_date',
        'memo',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'request_date' => 'date',
            'disbursed_date' => 'date',
            'settled_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISBURSED = 'disbursed';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    public static $statuses = [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_DISBURSED, self::STATUS_SETTLED, self::STATUS_CANCELLED];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function disbursementEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disbursement_journal_entry_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->morphMany(EmployeeTransaction::class, 'source');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'employee_id' => $this->employee->public_id,
            'branch_id' => $this->branch?->public_id,
            'amount_minor' => $this->amount_minor,
            'outstanding_minor' => $this->outstanding_minor,
            'status' => $this->status,
            'cash_account_id' => $this->cashAccount?->public_id,
            'request_date' => $this->request_date->toDateString(),
            'disbursed_date' => $this->disbursed_date?->toDateString(),
            'settled_date' => $this->settled_date?->toDateString(),
            'memo' => $this->memo,
        ];
    }
}
