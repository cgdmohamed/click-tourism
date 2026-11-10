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
 * Employee custody / petty-cash item. Lifecycle: issued -> cleared -> returned
 * (or closed). Issuance posts DR employee custody / CR cash; clearance posts
 * CR custody / DR expense; return posts CR custody / DR cash.
 */
class EmployeeCustody extends Model
{
    use HasPublicId;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'amount_minor',
        'outstanding_minor',
        'status',
        'issuance_journal_entry_id',
        'cash_account_id',
        'item_description',
        'issue_date',
        'returned_date',
        'memo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'issue_date' => 'date',
            'returned_date' => 'date',
        ];
    }

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CLOSED = 'closed';

    public static $statuses = [self::STATUS_ISSUED, self::STATUS_CLEARED, self::STATUS_RETURNED, self::STATUS_CLOSED];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function issuanceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'issuance_journal_entry_id');
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
            'item_description' => $this->item_description,
            'issue_date' => $this->issue_date->toDateString(),
            'returned_date' => $this->returned_date?->toDateString(),
            'memo' => $this->memo,
        ];
    }
}
