<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported bank statement file for a registered bank account.
 *
 * Line hashes (per account) and the file hash make imports idempotent:
 * re-uploading the same file is rejected and duplicated lines from
 * overlapping statements are skipped instead of double counted.
 */
class BankStatement extends Model
{
    use HasPublicId;

    protected $fillable = [
        'cash_account_id',
        'branch_id',
        'imported_by',
        'filename',
        'file_hash',
        'format',
        'period_from',
        'period_to',
        'opening_balance_minor',
        'closing_balance_minor',
        'currency_code',
        'line_count',
        'mapping',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'opening_balance_minor' => 'integer',
            'closing_balance_minor' => 'integer',
            'line_count' => 'integer',
            'mapping' => 'array',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function toApiArray(bool $withLines = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'cash_account_id' => $this->cashAccount?->public_id,
            'branch_id' => $this->branch?->public_id,
            'filename' => $this->filename,
            'format' => $this->format,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'opening_balance_minor' => $this->opening_balance_minor,
            'closing_balance_minor' => $this->closing_balance_minor,
            'currency' => $this->currency_code,
            'line_count' => $this->line_count,
            'mapping' => $this->mapping,
            'imported_at' => $this->created_at?->toIso8601String(),
        ];

        if ($withLines) {
            $payload['lines'] = $this->lines->map(fn (BankStatementLine $line) => $line->toApiArray())->values()->all();
        }

        return $payload;
    }
}
