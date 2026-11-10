<?php

namespace App\Modules\Accounting\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled recognition or reversal posting of an accounting schedule.
 *
 * Pending and failed events are the exception queue; posted events carry the
 * immutable journal reference. Posting is idempotent per event.
 */
class AccountingScheduleEvent extends Model
{
    use HasPublicId;

    public const KIND_RECOGNITION = 'recognition';

    public const KIND_REVERSAL = 'reversal';

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_FAILED];

    protected $fillable = [
        'accounting_schedule_id',
        'sequence',
        'kind',
        'reverses_event_id',
        'status',
        'due_date',
        'amount_minor',
        'journal_entry_id',
        'failure_reason',
        'posted_at',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function schedule()
    {
        return $this->belongsTo(AccountingSchedule::class, 'accounting_schedule_id');
    }

    public function reversesEvent()
    {
        return $this->belongsTo(self::class, 'reverses_event_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'sequence' => $this->sequence,
            'kind' => $this->kind,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'amount_minor' => $this->amount_minor,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'reverses_event_id' => $this->reversesEvent?->public_id,
            'failure_reason' => $this->failure_reason,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by' => $this->posted_by,
        ];
    }
}
