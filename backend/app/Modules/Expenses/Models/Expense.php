<?php

namespace App\Modules\Expenses\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number',
        'branch_id',
        'expense_category_id',
        'expense_date',
        'payee_type',
        'payee_id',
        'payee_name',
        'cash_account_id',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'amount_minor',
        'tax_minor',
        'description',
        'reference',
        'status',
        'created_by',
        'submitted_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'fx_rate_date' => 'date',
            'approved_at' => 'datetime',
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
            'fx_rate' => 'decimal:10',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'created_via' => $this->created_via,
            'number' => $this->number,
            'expense_date' => $this->expense_date?->toDateString(),
            'category' => $this->category?->toApiArray(),
            'payee_type' => $this->payee_type,
            'payee_name' => $this->payee_name,
            'cash_account_id' => $this->cashAccount?->public_id,
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'amount_minor' => $this->amount_minor,
            'tax_minor' => $this->tax_minor,
            'net_minor' => $this->amount_minor - $this->tax_minor,
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'journal_entry_id' => $this->journalEntry?->public_id,
        ];
    }
}
