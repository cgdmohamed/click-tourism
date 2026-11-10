<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accounting document / voucher (Prompt 22).
 *
 * Traceable source document (receipt, payment, transfer, adjustment, refund
 * voucher) with its own lifecycle: draft -> submitted -> approved -> posted.
 * Posted documents create journal entries through PostingService and are
 * immutable; corrections are made via a linked reversal/correction document.
 */
class AccountingDocument extends Model
{
    use HasPublicId;

    protected $fillable = [
        'type',
        'series',
        'number',
        'party_type',
        'party_id',
        'branch_id',
        'booking_id',
        'cash_account_id',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'status',
        'subtotal_minor',
        'tax_minor',
        'total_minor',
        'journal_entry_id',
        'reversal_of_id',
        'reversal_reason',
        'idempotency_key',
        'created_by',
        'submitted_by',
        'approved_by',
        'posted_by',
        'submitted_at',
        'approved_at',
        'posted_at',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'fx_rate_date' => 'date',
            'fx_rate' => 'decimal:10',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public const TYPE_RECEIPT_VOUCHER = 'receipt_voucher';

    public const TYPE_PAYMENT_VOUCHER = 'payment_voucher';

    public const TYPE_TRANSFER_VOUCHER = 'transfer_voucher';

    public const TYPE_ADJUSTMENT_VOUCHER = 'adjustment_voucher';

    public const TYPE_REFUND_VOUCHER = 'refund_voucher';

    public static $types = [
        self::TYPE_RECEIPT_VOUCHER,
        self::TYPE_PAYMENT_VOUCHER,
        self::TYPE_TRANSFER_VOUCHER,
        self::TYPE_ADJUSTMENT_VOUCHER,
        self::TYPE_REFUND_VOUCHER,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public static $statuses = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingDocumentLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function party()
    {
        return match ($this->party_type) {
            'customer' => Customer::query()->find($this->party_id),
            'supplier' => Supplier::query()->find($this->party_id),
            'employee' => Employee::query()->find($this->party_id),
            default => null,
        };
    }

    public function toApiArray(bool $withLines = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'type' => $this->type,
            'series' => $this->series,
            'number' => $this->number,
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
            'party' => $this->party()?->toApiArray(),
            'branch_id' => $this->branch?->public_id,
            'booking_id' => $this->booking_id,
            'cash_account_id' => $this->cash_account_id,
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'fx_rate_date' => $this->fx_rate_date?->toDateString(),
            'fx_rate_source' => $this->fx_rate_source,
            'status' => $this->status,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'reversal_of_id' => $this->reversalOf?->public_id,
            'reversal_reason' => $this->reversal_reason,
            'memo' => $this->memo,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'posted_by' => $this->posted_by,
        ];

        if ($withLines) {
            $payload['lines'] = $this->lines->map(fn (AccountingDocumentLine $line) => $line->toApiArray())->values()->all();
        }

        return $payload;
    }
}
