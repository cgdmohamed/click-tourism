<?php

namespace App\Modules\Accounting\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'branch_id',
        'debit_minor',
        'credit_minor',
        'base_debit_minor',
        'base_credit_minor',
        'currency_code',
        'party_type',
        'party_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'base_debit_minor' => 'integer',
            'base_credit_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Journal lines are immutable once created.');
        });

        static::deleting(function () {
            throw new DomainException('Journal lines cannot be deleted; reverse the entry instead.');
        });
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
