<?php

namespace App\Modules\Treasury\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One transaction row of an imported bank statement.
 *
 * `amount_minor` is signed: positive is money in (a deposit, matching a book
 * debit), negative is money out (a withdrawal, matching a book credit).
 * Rows are immutable: correction happens by importing a corrected statement
 * or by matching/unmatching, never by editing the imported data.
 */
class BankStatementLine extends Model
{
    use HasPublicId;

    protected $fillable = [
        'bank_statement_id',
        'cash_account_id',
        'line_hash',
        'line_date',
        'value_date',
        'amount_minor',
        'balance_after_minor',
        'bank_reference',
        'description',
        'party_name',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'value_date' => 'date',
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'raw' => 'array',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'line_date' => $this->line_date?->toDateString(),
            'value_date' => $this->value_date?->toDateString(),
            'amount_minor' => $this->amount_minor,
            'balance_after_minor' => $this->balance_after_minor,
            'bank_reference' => $this->bank_reference,
            'description' => $this->description,
            'party_name' => $this->party_name,
        ];
    }
}
