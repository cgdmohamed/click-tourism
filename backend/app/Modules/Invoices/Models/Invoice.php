<?php

namespace App\Modules\Invoices\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Support\CarriesWarnings;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Customer invoice (AR), supplier invoice (AP) or credit note.
 *
 * Lifecycle: draft -> submitted -> approved -> issued (posted through
 * PostingService). Only drafts are editable; issued invoices are corrected by
 * a linked credit note or reversal, never edited or deleted.
 */
class Invoice extends Model
{
    use CarriesWarnings;
    use HasPublicId;

    protected $fillable = [
        'type',
        'series',
        'number',
        'party_type',
        'party_id',
        'branch_id',
        'booking_id',
        'issue_date',
        'due_date',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'status',
        'subtotal_minor',
        'tax_minor',
        'total_minor',
        'outstanding_minor',
        'journal_entry_id',
        'credit_note_of_id',
        'idempotency_key',
        'created_by',
        'approved_by',
        'approved_at',
        'issued_at',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'fx_rate_date' => 'date',
            'fx_rate' => 'decimal:10',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public const TYPE_CUSTOMER_INVOICE = 'customer_invoice';

    public const TYPE_SUPPLIER_INVOICE = 'supplier_invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public static $types = [
        self::TYPE_CUSTOMER_INVOICE,
        self::TYPE_SUPPLIER_INVOICE,
        self::TYPE_CREDIT_NOTE,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOIDED = 'voided';

    public static $statuses = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_ISSUED,
        self::STATUS_VOIDED,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creditNoteOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credit_note_of_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(InvoiceApplication::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credit_note_of_id');
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
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
            'party' => $this->party?->toApiArray(),
            'branch_id' => $this->branch?->public_id,
            'booking_id' => $this->booking_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'status' => $this->status,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'outstanding_minor' => $this->outstanding_minor,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'credit_note_of_id' => $this->creditNoteOf?->public_id,
            'memo' => $this->memo,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_by' => $this->created_by,
        ];

        if ($withLines) {
            $payload['lines'] = $this->lines->map(fn (InvoiceLine $line) => $line->toApiArray())->values()->all();
            $payload['applications'] = $this->applications()->with('payment', 'supplierPayment')->orderBy('id')->get()
                ->map(fn (InvoiceApplication $application) => $application->toApiArray() + [
                    'payment_number' => $application->payment?->number,
                ])->values()->all();
        }

        if ($this->warnings() !== []) {
            $payload['warnings'] = $this->warnings();
        }

        return $payload;
    }
}
