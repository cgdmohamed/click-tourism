<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number',
        'branch_id',
        'from_cash_account_id',
        'to_cash_account_id',
        'transfer_date',
        'from_currency',
        'from_amount_minor',
        'from_fx_rate',
        'to_currency',
        'to_amount_minor',
        'to_fx_rate',
        'fee_minor',
        'memo',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'from_amount_minor' => 'integer',
            'to_amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'from_fx_rate' => 'decimal:10',
            'to_fx_rate' => 'decimal:10',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function from()
    {
        return $this->belongsTo(CashAccount::class, 'from_cash_account_id');
    }

    public function to()
    {
        return $this->belongsTo(CashAccount::class, 'to_cash_account_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'from' => $this->from?->toApiArray(),
            'to' => $this->to?->toApiArray(),
            'from_currency' => $this->from_currency,
            'from_amount_minor' => $this->from_amount_minor,
            'from_fx_rate' => (string) $this->from_fx_rate,
            'to_currency' => $this->to_currency,
            'to_amount_minor' => $this->to_amount_minor,
            'to_fx_rate' => (string) $this->to_fx_rate,
            'fee_minor' => $this->fee_minor,
            'memo' => $this->memo,
            'status' => $this->status,
            'journal_entry_id' => $this->journalEntry?->public_id,
        ];
    }
}
