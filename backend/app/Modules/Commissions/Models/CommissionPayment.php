<?php

namespace App\Modules\Commissions\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Settlement of (part of) a posted commission payable: Dr payable / Cr cash or bank. */
class CommissionPayment extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number', 'commission_id', 'branch_id', 'cash_account_id', 'amount_minor', 'currency_code',
        'paid_on', 'reference', 'status', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date'];
    }

    public function commission()
    {
        return $this->belongsTo(Commission::class);
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
            'number' => $this->number,
            'commission_id' => $this->commission?->public_id,
            'cash_account_id' => $this->cashAccount?->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency_code,
            'paid_on' => $this->paid_on?->toDateString(),
            'reference' => $this->reference,
            'status' => $this->status,
            'journal_entry_id' => $this->journalEntry?->public_id,
        ];
    }
}
