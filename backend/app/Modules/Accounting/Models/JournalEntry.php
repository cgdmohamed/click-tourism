<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number',
        'entry_date',
        'branch_id',
        'fiscal_period_id',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'status',
        'memo',
        'source_type',
        'source_id',
        'event',
        'reversal_of_id',
        'reversal_reason',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'fx_rate_date' => 'date',
            'posted_at' => 'datetime',
            'fx_rate' => 'decimal:10',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            if ($entry->getOriginal('status') === 'posted') {
                throw new DomainException('Posted journal entries are immutable; create a reversal instead.');
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->status === 'posted') {
                throw new DomainException('Posted journal entries cannot be deleted; create a reversal instead.');
            }
        });
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function period()
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function totalMinor(): int
    {
        // Lines of different currencies cannot be added up; such an entry
        // (a cross-currency transfer) is totalled in the base currency.
        $mixed = $this->lines
            ->filter(fn ($line) => (int) $line->debit_minor + (int) $line->credit_minor > 0)
            ->pluck('currency_code')->unique()->count() > 1;

        return (int) $this->lines->sum($mixed ? 'base_debit_minor' : 'debit_minor');
    }

    public function toApiArray(bool $withLines = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'number' => $this->number,
            'entry_date' => $this->entry_date?->toDateString(),
            'status' => $this->status,
            'memo' => $this->memo,
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'total_minor' => $this->totalMinor(),
            'reversal_of_id' => $this->reversalOf?->public_id,
            'reversal_reason' => $this->reversal_reason,
            'reversed_by_id' => $this->relationLoaded('reversal') ? $this->reversal?->public_id : null,
            'source_type' => $this->source_type,
            'event' => $this->event,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_by_id' => $this->creator?->public_id,
        ];

        if ($withLines) {
            $payload['lines'] = $this->lines->map(fn (JournalLine $line) => [
                'id' => (string) $line->id,
                'account_id' => $line->account?->public_id,
                'account' => $line->account?->toApiArray(),
                'debit_minor' => (int) $line->debit_minor,
                'credit_minor' => (int) $line->credit_minor,
                'description' => $line->description,
            ])->values()->all();
        }

        return $payload;
    }
}
