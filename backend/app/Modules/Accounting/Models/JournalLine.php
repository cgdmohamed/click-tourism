<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    use HasPublicId;

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

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Journal lines keep internal ids in existing routes (cost allocations);
     * the ULID `public_id` is only used by reconciliation matches.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
