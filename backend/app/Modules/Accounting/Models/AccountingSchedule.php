<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Accrual, prepayment or deferred revenue recognition schedule (Prompt 24).
 *
 * The schedule is the approved source document. Its events are the only
 * things that touch the ledger, always through PostingService. A draft may be
 * edited; once active the events and their journal links are immutable.
 */
class AccountingSchedule extends Model
{
    use HasPublicId;

    public const KIND_PREPAID_EXPENSE = 'prepaid_expense';

    public const KIND_DEFERRED_REVENUE = 'deferred_revenue';

    public const KIND_ACCRUED_EXPENSE = 'accrued_expense';

    public const KIND_ACCRUED_REVENUE = 'accrued_revenue';

    /** @var array<int, string> */
    public const KINDS = [
        self::KIND_PREPAID_EXPENSE,
        self::KIND_DEFERRED_REVENUE,
        self::KIND_ACCRUED_EXPENSE,
        self::KIND_ACCRUED_REVENUE,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const REVERSAL_NONE = 'none';

    public const REVERSAL_NEXT_PERIOD = 'next_period';

    /** @var array<int, string> */
    public const REVERSAL_POLICIES = [self::REVERSAL_NONE, self::REVERSAL_NEXT_PERIOD];

    protected $fillable = [
        'number',
        'branch_id',
        'kind',
        'status',
        'description',
        'reference',
        'currency_code',
        'fx_rate',
        'fx_rate_date',
        'fx_rate_source',
        'total_minor',
        'periods',
        'frequency',
        'starts_on',
        'ends_on',
        'source_account_id',
        'target_account_id',
        'reversal_policy',
        'recognized_minor',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'adjustment_document_ids',
    ];

    protected function casts(): array
    {
        return [
            'fx_rate' => 'decimal:10',
            'fx_rate_date' => 'date',
            'total_minor' => 'integer',
            'periods' => 'integer',
            'recognized_minor' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'adjustment_document_ids' => 'array',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceAccount()
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    public function targetAccount()
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function events()
    {
        return $this->hasMany(AccountingScheduleEvent::class)->orderBy('due_date')->orderBy('kind')->orderBy('sequence');
    }

    public function isAccrual(): bool
    {
        return in_array($this->kind, [self::KIND_ACCRUED_EXPENSE, self::KIND_ACCRUED_REVENUE], true);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        $events = $this->relationLoaded('events') ? $this->events : $this->events()->get();

        $recognized = (int) $events
            ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
            ->where('status', AccountingScheduleEvent::STATUS_POSTED)
            ->sum('amount_minor');
        $reversed = (int) $events
            ->where('kind', AccountingScheduleEvent::KIND_REVERSAL)
            ->where('status', AccountingScheduleEvent::STATUS_POSTED)
            ->sum('amount_minor');

        $pending = $events
            ->whereIn('status', [AccountingScheduleEvent::STATUS_PENDING, AccountingScheduleEvent::STATUS_FAILED])
            ->sortBy('due_date')
            ->first();

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'branch_id' => $this->branch?->public_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'description' => $this->description,
            'reference' => $this->reference,
            'currency' => $this->currency_code,
            'fx_rate' => (string) $this->fx_rate,
            'fx_rate_date' => $this->fx_rate_date?->toDateString(),
            'fx_rate_source' => $this->fx_rate_source,
            'total_minor' => $this->total_minor,
            'periods' => $this->periods,
            'frequency' => $this->frequency,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'reversal_policy' => $this->reversal_policy,
            'source_account' => $this->sourceAccount?->toApiArray(),
            'target_account' => $this->targetAccount?->toApiArray(),
            'recognized_minor' => $recognized,
            'reversed_minor' => $reversed,
            'remaining_minor' => max(0, $this->total_minor - $recognized),
            'outstanding_minor' => $recognized - $reversed,
            'next_due_date' => $pending?->due_date?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancel_reason' => $this->cancel_reason,
            'adjustment_document_ids' => $this->adjustment_document_ids ?? [],
            'created_by' => $this->created_by,
            'events' => $events->map(fn (AccountingScheduleEvent $event) => $event->toApiArray())->values()->all(),
        ];
    }
}
