<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reconciliation session for one bank account and period (Prompt 23).
 *
 * A session never writes to the ledger: it only links imported statement
 * lines to existing posted journal lines. Approved sessions are locked and
 * keep a JSON snapshot of the balances, matches and approved differences.
 */
class BankReconciliation extends Model
{
    use HasPublicId;

    public const STATUS_OPEN = 'open';

    public const STATUS_APPROVED = 'approved';

    public static $statuses = [self::STATUS_OPEN, self::STATUS_APPROVED];

    protected $fillable = [
        'cash_account_id',
        'branch_id',
        'bank_statement_id',
        'period_from',
        'period_to',
        'currency_code',
        'opening_book_balance_minor',
        'opening_bank_balance_minor',
        'closing_bank_balance_minor',
        'closing_book_balance_minor',
        'status',
        'adjustment_document_ids',
        'snapshot',
        'created_by',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'opening_book_balance_minor' => 'integer',
            'opening_bank_balance_minor' => 'integer',
            'closing_bank_balance_minor' => 'integer',
            'closing_book_balance_minor' => 'integer',
            'adjustment_document_ids' => 'array',
            'snapshot' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'cash_account_id' => $this->cashAccount?->public_id,
            'cash_account' => $this->cashAccount?->toApiArray(),
            'branch_id' => $this->branch?->public_id,
            'statement_id' => $this->statement?->public_id,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'currency' => $this->currency_code,
            'opening_book_balance_minor' => $this->opening_book_balance_minor,
            'opening_bank_balance_minor' => $this->opening_bank_balance_minor,
            'closing_bank_balance_minor' => $this->closing_bank_balance_minor,
            'closing_book_balance_minor' => $this->closing_book_balance_minor,
            'status' => $this->status,
            'notes' => $this->notes,
            'adjustment_document_ids' => $this->adjustment_document_ids ?? [],
            'snapshot' => $this->snapshot,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
