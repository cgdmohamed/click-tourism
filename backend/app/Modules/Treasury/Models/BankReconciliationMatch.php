<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\JournalLine;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank-line / book-line pair inside a reconciliation match.
 *
 * Rows that share a `match_group` form a single match: a simple 1:1 match has
 * one row, split/group matches have several rows whose amounts add up to the
 * full amount of every involved line (within the configured tolerance).
 */
class BankReconciliationMatch extends Model
{
    use HasPublicId;

    protected $fillable = [
        'bank_reconciliation_id',
        'match_group',
        'bank_statement_line_id',
        'journal_line_id',
        'amount_minor',
        'kind',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function bankLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
