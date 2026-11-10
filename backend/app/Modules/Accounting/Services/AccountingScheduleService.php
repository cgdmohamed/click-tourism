<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Accounting\Models\AccountingScheduleEvent;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accrual, prepayment and deferred-revenue schedules (Prompt 24).
 *
 * Nothing here writes journals directly: every recognition and reversal goes
 * through PostingService with a deterministic per-event idempotency key, so a
 * rerun of the scheduler can never duplicate a posting. Closed periods are
 * recorded in the exception queue instead of being bypassed (DEC-038).
 */
class AccountingScheduleService
{
    public const SOURCE_TYPE = 'accounting_schedule';

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountingDocumentService $documents,
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly FxService $fx,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Branch $branch, ?int $actorId): AccountingSchedule
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $attributes = $this->attributes($data, $branch);

            $schedule = AccountingSchedule::query()->create($attributes + [
                'number' => $this->sequences->next('schedule', $branch, Carbon::parse($attributes['starts_on'])),
                'branch_id' => $branch->id,
                'status' => AccountingSchedule::STATUS_DRAFT,
                'created_by' => $actorId,
            ]);

            $this->buildEvents($schedule);

            $this->audit->log('accounting_schedule.created', $schedule, null, [
                'number' => $schedule->number,
                'kind' => $schedule->kind,
                'total_minor' => $schedule->total_minor,
                'periods' => $schedule->periods,
            ], $actorId);

            return $this->load($schedule);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AccountingSchedule $schedule, array $data, ?int $actorId): AccountingSchedule
    {
        return DB::transaction(function () use ($schedule, $data, $actorId) {
            $schedule = AccountingSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! $schedule->isEditable()) {
                throw new DomainException('Only draft schedules can be edited.');
            }

            $merged = array_merge($this->editable($schedule), $data);
            $schedule->update($this->attributes($merged, $schedule->branch));

            $this->buildEvents($schedule);

            $this->audit->log('accounting_schedule.updated', $schedule, null, ['number' => $schedule->number], $actorId);

            return $this->load($schedule);
        });
    }

    /**
     * Activate a draft schedule. Activation is the approval step: it generates
     * the events, then posts any that are already due when auto-posting is on.
     */
    public function activate(AccountingSchedule $schedule, ?int $actorId): AccountingSchedule
    {
        return DB::transaction(function () use ($schedule, $actorId) {
            $schedule = AccountingSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if ($schedule->status !== AccountingSchedule::STATUS_DRAFT) {
                throw new DomainException('Only draft schedules can be activated.');
            }

            if ($schedule->events()->count() === 0) {
                $this->buildEvents($schedule);
            }

            $schedule->update([
                'status' => AccountingSchedule::STATUS_ACTIVE,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            $this->audit->log('accounting_schedule.activated', $schedule, [
                'status' => AccountingSchedule::STATUS_DRAFT,
            ], [
                'status' => AccountingSchedule::STATUS_ACTIVE,
                'events' => $schedule->events()->count(),
            ], $actorId);

            if ($this->settings->bool('accounting', 'schedule_auto_post', true)) {
                $this->postDueForSchedule($schedule, $actorId);
            }

            return $this->load($schedule);
        });
    }

    /**
     * Cancel a schedule without touching posted history. Pending recognitions
     * are cancelled; the unamortized balance of a prepayment or deferred
     * revenue can be written off through a draft adjustment voucher that still
     * needs normal approval before it reaches the ledger.
     *
     * @param  array<string, mixed>  $data
     * @return array{schedule: AccountingSchedule, adjustment_document: ?AccountingDocument}
     */
    public function cancel(AccountingSchedule $schedule, array $data, ?int $actorId): array
    {
        return DB::transaction(function () use ($schedule, $data, $actorId) {
            $schedule = AccountingSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! in_array($schedule->status, [AccountingSchedule::STATUS_DRAFT, AccountingSchedule::STATUS_ACTIVE], true)) {
                throw new DomainException('Only draft or active schedules can be cancelled.');
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('A cancellation reason is required.');
            }

            $recognized = (int) $schedule->events()
                ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
                ->where('status', AccountingScheduleEvent::STATUS_POSTED)
                ->sum('amount_minor');
            $remaining = max(0, $schedule->total_minor - $recognized);

            // Pending recognitions stop. A reversal of an already posted
            // accrual must still run, otherwise the liability/asset would be
            // left on the books forever.
            $schedule->events()
                ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
                ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
                ->update(['status' => AccountingScheduleEvent::STATUS_CANCELLED]);

            foreach ($schedule->events()
                ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
                ->where('kind', AccountingScheduleEvent::KIND_REVERSAL)
                ->with('reversesEvent')
                ->get() as $event) {
                if (! $event->reversesEvent || $event->reversesEvent->status !== AccountingScheduleEvent::STATUS_POSTED) {
                    $event->update(['status' => AccountingScheduleEvent::STATUS_CANCELLED]);
                }
            }

            $document = null;
            if (! empty($data['write_off']) && $remaining > 0) {
                $document = $this->writeOff($schedule, $remaining, $data, $actorId);
            }

            $ids = $schedule->adjustment_document_ids ?? [];
            if ($document) {
                $ids[] = $document->public_id;
            }

            $schedule->update([
                'status' => AccountingSchedule::STATUS_CANCELLED,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'cancel_reason' => Str::limit($reason, 250, ''),
                'adjustment_document_ids' => array_values(array_unique($ids)),
            ]);

            $this->audit->log('accounting_schedule.cancelled', $schedule, null, [
                'reason' => $reason,
                'remaining_minor' => $remaining,
                'write_off_document' => $document?->public_id,
            ], $actorId);

            return [
                'schedule' => $this->load($schedule),
                'adjustment_document' => $document?->load('lines.account'),
            ];
        });
    }

    /**
     * Manually post one event (used to retry an exception after a period is
     * reopened). Throws when the event still cannot be posted.
     */
    public function postEvent(AccountingSchedule $schedule, AccountingScheduleEvent $event, ?int $actorId): AccountingScheduleEvent
    {
        if ($event->accounting_schedule_id !== $schedule->id) {
            throw new DomainException('The event does not belong to this schedule.');
        }

        $result = DB::transaction(fn () => $this->attempt($schedule, $event, $actorId));

        if (! $result['posted']) {
            throw new DomainException($result['reason'] ?? 'The schedule event could not be posted.');
        }

        return AccountingScheduleEvent::query()
            ->with('journalEntry', 'reversesEvent')
            ->findOrFail($event->id);
    }

    /**
     * Post every due event. Retry-safe: already posted events are skipped and
     * a rerun never creates a second journal for the same event.
     *
     * @return array{as_of: string, processed: int, posted: int, failed: int, results: array<int, array<string, mixed>>}
     */
    public function runDue(?int $actorId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $events = AccountingScheduleEvent::query()
            ->with('schedule.branch', 'schedule.sourceAccount', 'schedule.targetAccount', 'reversesEvent')
            ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
            ->whereDate('due_date', '<=', $asOf->toDateString())
            ->whereHas('schedule', fn ($query) => $query->whereIn('status', [
                AccountingSchedule::STATUS_ACTIVE,
                AccountingSchedule::STATUS_CANCELLED,
            ]))
            ->orderBy('due_date')
            ->orderBy('accounting_schedule_id')
            ->orderByRaw("CASE WHEN kind = 'recognition' THEN 0 ELSE 1 END")
            ->orderBy('sequence')
            ->get();

        $posted = 0;
        $failed = 0;
        $results = [];

        foreach ($events as $event) {
            $schedule = $event->schedule;

            if (! $schedule) {
                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->attempt($schedule, $event, $actorId));
            } catch (\Throwable $exception) {
                // Business failures are recorded by attempt(); anything else
                // (a database error, for example) must leave the event pending
                // so the next run can retry it safely.
                $result = ['posted' => false, 'reason' => $exception->getMessage()];
            }

            if ($result['posted']) {
                $posted++;
            } else {
                $failed++;
            }

            $results[] = [
                'event_id' => $event->public_id,
                'schedule_id' => $schedule->public_id,
                'due_date' => $event->due_date?->toDateString(),
                'kind' => $event->kind,
                'posted' => $result['posted'],
                'reason' => $result['reason'] ?? null,
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'processed' => $events->count(),
            'posted' => $posted,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Scheduled events due in the next N days for the calendar/upcoming view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(?Branch $branch, int $days = 30): array
    {
        $from = Carbon::today();
        $to = Carbon::today()->addDays(max(1, $days));

        return AccountingScheduleEvent::query()
            ->with('schedule.branch')
            ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
            ->whereDate('due_date', '>=', $from->toDateString())
            ->whereDate('due_date', '<=', $to->toDateString())
            ->whereHas('schedule', function ($query) use ($branch) {
                $query->where('status', AccountingSchedule::STATUS_ACTIVE)
                    ->when($branch, fn ($inner) => $inner->where('branch_id', $branch->id));
            })
            ->orderBy('due_date')
            ->orderBy('accounting_schedule_id')
            ->get()
            ->map(fn (AccountingScheduleEvent $event) => $this->eventRow($event))
            ->values()
            ->all();
    }

    /**
     * Exception queue: events the scheduler could not post.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exceptions(?Branch $branch): array
    {
        return AccountingScheduleEvent::query()
            ->with('schedule.branch')
            ->where('status', AccountingScheduleEvent::STATUS_FAILED)
            ->when($branch, fn ($query) => $query->whereHas(
                'schedule',
                fn ($inner) => $inner->where('branch_id', $branch->id),
            ))
            ->orderBy('due_date')
            ->orderBy('accounting_schedule_id')
            ->get()
            ->map(fn (AccountingScheduleEvent $event) => $this->eventRow($event))
            ->values()
            ->all();
    }

    public function load(AccountingSchedule $schedule): AccountingSchedule
    {
        return $schedule->refresh()->load(
            'branch',
            'sourceAccount',
            'targetAccount',
            'events.journalEntry',
            'events.reversesEvent',
        );
    }

    // ------------------------------------------------------------------
    // Event generation
    // ------------------------------------------------------------------

    private function buildEvents(AccountingSchedule $schedule): void
    {
        if ($schedule->events()->where('status', AccountingScheduleEvent::STATUS_POSTED)->exists()) {
            throw new DomainException('A schedule with posted events cannot be regenerated.');
        }

        $schedule->events()->delete();

        $base = intdiv($schedule->total_minor, $schedule->periods);
        $residual = $schedule->total_minor - ($base * $schedule->periods);
        $offset = max(0, $this->settings->int('accounting', 'accrual_reversal_offset_days', 1));
        $start = Carbon::parse($schedule->starts_on->toDateString());
        $reverses = $schedule->isAccrual() && $schedule->reversal_policy === AccountingSchedule::REVERSAL_NEXT_PERIOD;

        for ($sequence = 1; $sequence <= $schedule->periods; $sequence++) {
            // Rounding residual goes on the final period so the events add up
            // to the schedule total exactly.
            $amount = $base + ($sequence === $schedule->periods ? $residual : 0);
            $due = $this->periodDueDate($start, $sequence);

            $recognition = $schedule->events()->create([
                'sequence' => $sequence,
                'kind' => AccountingScheduleEvent::KIND_RECOGNITION,
                'status' => AccountingScheduleEvent::STATUS_PENDING,
                'due_date' => $due->toDateString(),
                'amount_minor' => $amount,
            ]);

            if ($reverses) {
                $schedule->events()->create([
                    'sequence' => $sequence,
                    'kind' => AccountingScheduleEvent::KIND_REVERSAL,
                    'reverses_event_id' => $recognition->id,
                    'status' => AccountingScheduleEvent::STATUS_PENDING,
                    'due_date' => $due->copy()->addDays($offset)->toDateString(),
                    'amount_minor' => $amount,
                ]);
            }
        }
    }

    private function periodDueDate(Carbon $start, int $sequence): Carbon
    {
        return $start->copy()
            ->startOfMonth()
            ->addMonthsNoOverflow($sequence - 1)
            ->endOfMonth();
    }

    // ------------------------------------------------------------------
    // Posting
    // ------------------------------------------------------------------

    /**
     * @return array{posted: bool, reason?: string, journal_entry_id?: string, already?: bool}
     */
    private function attempt(AccountingSchedule $schedule, AccountingScheduleEvent $event, ?int $actorId): array
    {
        $event = AccountingScheduleEvent::query()->lockForUpdate()->findOrFail($event->id);
        $schedule = AccountingSchedule::query()
            ->with('branch', 'sourceAccount', 'targetAccount')
            ->findOrFail($schedule->id);

        if ($event->status === AccountingScheduleEvent::STATUS_POSTED) {
            return ['posted' => true, 'already' => true];
        }

        if ($event->status === AccountingScheduleEvent::STATUS_CANCELLED) {
            return ['posted' => false, 'reason' => 'This schedule event was cancelled.'];
        }

        if ($schedule->status === AccountingSchedule::STATUS_CANCELLED
            && $event->kind === AccountingScheduleEvent::KIND_RECOGNITION) {
            return $this->fail($event, 'The schedule was cancelled; no further recognition will be posted.');
        }

        if ($event->kind === AccountingScheduleEvent::KIND_REVERSAL) {
            $recognition = $event->reversesEvent;
            if (! $recognition || $recognition->status !== AccountingScheduleEvent::STATUS_POSTED) {
                return $this->fail($event, 'The related recognition has not been posted yet.');
            }
        }

        if (! $schedule->branch) {
            return $this->fail($event, 'The schedule has no branch and cannot be posted.');
        }

        // Retry-safe: a previous run may have posted the journal but crashed
        // before marking the event. Adopt that journal instead of posting a
        // duplicate (the ledger also enforces the idempotency key).
        $existing = JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $schedule->id)
            ->where('event', $this->eventKey($event))
            ->first();

        if ($existing) {
            $this->markPosted($event, $existing, $actorId);
            $this->refreshRecognized($schedule);

            return ['posted' => true, 'journal_entry_id' => $existing->public_id, 'already' => true];
        }

        try {
            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $event->due_date->toDateString(),
                branch: $schedule->branch,
                currency: $schedule->currency_code,
                lines: $this->lines($schedule, $event),
                memo: $this->memo($schedule, $event),
                sourceType: self::SOURCE_TYPE,
                sourceId: $schedule->id,
                event: $this->eventKey($event),
                fxRate: (string) $schedule->fx_rate,
                fxRateDate: $schedule->fx_rate_date->toDateString(),
                fxRateSource: $schedule->fx_rate_source,
                actorId: $actorId,
            ));
        } catch (DomainException|AuthorizationException $exception) {
            return $this->fail($event, $exception->getMessage());
        }

        $this->markPosted($event, $entry, $actorId);
        $this->refreshRecognized($schedule);
        $this->completeIfDone($schedule);

        return ['posted' => true, 'journal_entry_id' => $entry->public_id];
    }

    /** @return array{posted: bool, reason: string} */
    private function fail(AccountingScheduleEvent $event, string $reason): array
    {
        $event->update([
            'status' => AccountingScheduleEvent::STATUS_FAILED,
            'failure_reason' => Str::limit($reason, 250, ''),
        ]);

        return ['posted' => false, 'reason' => $reason];
    }

    private function markPosted(AccountingScheduleEvent $event, JournalEntry $entry, ?int $actorId): void
    {
        $event->update([
            'status' => AccountingScheduleEvent::STATUS_POSTED,
            'failure_reason' => null,
            'journal_entry_id' => $entry->id,
            'posted_at' => now(),
            'posted_by' => $actorId,
        ]);
    }

    private function refreshRecognized(AccountingSchedule $schedule): void
    {
        $recognized = (int) $schedule->events()
            ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
            ->where('status', AccountingScheduleEvent::STATUS_POSTED)
            ->sum('amount_minor');

        $schedule->update(['recognized_minor' => $recognized]);
    }

    private function completeIfDone(AccountingSchedule $schedule): void
    {
        if ($schedule->status !== AccountingSchedule::STATUS_ACTIVE) {
            return;
        }

        $open = $schedule->events()
            ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
            ->exists();

        if (! $open) {
            $schedule->update(['status' => AccountingSchedule::STATUS_COMPLETED]);
            $this->audit->log('accounting_schedule.completed', $schedule, null, [
                'number' => $schedule->number,
            ]);
        }
    }

    private function eventKey(AccountingScheduleEvent $event): string
    {
        return 'event:'.$event->id;
    }

    private function memo(AccountingSchedule $schedule, AccountingScheduleEvent $event): string
    {
        $label = $event->kind === AccountingScheduleEvent::KIND_REVERSAL ? 'reversal' : 'recognition';

        return Str::limit(sprintf(
            'Schedule %s · %s %d/%d · %s',
            $schedule->number,
            $label,
            $event->sequence,
            $schedule->periods,
            $schedule->description,
        ), 250, '');
    }

    /**
     * Debit/credit sides follow the schedule kind. A reversal simply mirrors
     * the recognition, so the original journal never has to be edited.
     *
     * @return array<int, PostingLine>
     */
    private function lines(AccountingSchedule $schedule, AccountingScheduleEvent $event): array
    {
        $debitSource = in_array($schedule->kind, [
            AccountingSchedule::KIND_DEFERRED_REVENUE,
            AccountingSchedule::KIND_ACCRUED_REVENUE,
        ], true);

        if ($event->kind === AccountingScheduleEvent::KIND_REVERSAL) {
            $debitSource = ! $debitSource;
        }

        $debit = $debitSource ? $schedule->sourceAccount : $schedule->targetAccount;
        $credit = $debitSource ? $schedule->targetAccount : $schedule->sourceAccount;
        $amount = $event->amount_minor;
        $description = Str::limit($schedule->number.' · '.$schedule->description, 250, '');

        return [
            PostingLine::debit($debit, $amount, null, null, $description),
            PostingLine::credit($credit, $amount, null, null, $description),
        ];
    }

    private function postDueForSchedule(AccountingSchedule $schedule, ?int $actorId): void
    {
        $events = $schedule->events()
            ->whereIn('status', AccountingScheduleEvent::OPEN_STATUSES)
            ->whereDate('due_date', '<=', Carbon::today()->toDateString())
            ->orderBy('due_date')
            ->orderByRaw("CASE WHEN kind = 'recognition' THEN 0 ELSE 1 END")
            ->orderBy('sequence')
            ->get();

        foreach ($events as $event) {
            DB::transaction(fn () => $this->attempt($schedule, $event, $actorId));
        }
    }

    // ------------------------------------------------------------------
    // Cancellation support
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function writeOff(AccountingSchedule $schedule, int $remaining, array $data, ?int $actorId): AccountingDocument
    {
        if (! in_array($schedule->kind, [
            AccountingSchedule::KIND_PREPAID_EXPENSE,
            AccountingSchedule::KIND_DEFERRED_REVENUE,
        ], true)) {
            throw new DomainException('Only prepaid expenses and deferred revenue can be written off on cancellation.');
        }

        $source = $schedule->sourceAccount->public_id;
        $target = $schedule->targetAccount->public_id;

        $lines = $schedule->kind === AccountingSchedule::KIND_PREPAID_EXPENSE
            ? [
                ['account_id' => $target, 'debit_minor' => $remaining, 'description' => 'Write off prepayment '.$schedule->number],
                ['account_id' => $source, 'credit_minor' => $remaining, 'description' => 'Release prepayment '.$schedule->number],
            ]
            : [
                ['account_id' => $source, 'debit_minor' => $remaining, 'description' => 'Release deferred revenue '.$schedule->number],
                ['account_id' => $target, 'credit_minor' => $remaining, 'description' => 'Recognize deferred revenue '.$schedule->number],
            ];

        return $this->documents->create([
            'type' => AccountingDocument::TYPE_ADJUSTMENT_VOUCHER,
            'document_date' => (string) ($data['write_off_date'] ?? now()->toDateString()),
            'currency' => $schedule->currency_code,
            'memo' => (string) ($data['write_off_memo'] ?? ('Cancellation of schedule '.$schedule->number.' — write off remaining balance')),
            'lines' => $lines,
            'submit' => (bool) ($data['submit_write_off'] ?? false),
        ], $schedule->branch, $actorId);
    }

    // ------------------------------------------------------------------
    // Input handling
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Branch $branch): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, AccountingSchedule::KINDS, true)) {
            throw new DomainException('Choose a valid schedule kind.');
        }

        $total = (int) ($data['total_minor'] ?? 0);
        $periods = (int) ($data['periods'] ?? 0);
        if ($total <= 0) {
            throw new DomainException('The schedule total must be greater than zero.');
        }
        if ($periods < 1 || $periods > 120) {
            throw new DomainException('The number of periods must be between 1 and 120.');
        }
        if ($total < $periods) {
            throw new DomainException('The total must be at least one minor unit per period.');
        }

        $frequency = (string) ($data['frequency'] ?? 'monthly');
        if ($frequency !== 'monthly') {
            throw new DomainException('Only monthly recognition schedules are supported.');
        }

        $start = Carbon::parse((string) $data['starts_on'])->startOfDay();
        $lastDue = $this->periodDueDate($start, $periods);
        $endsOn = isset($data['ends_on']) && $data['ends_on'] !== ''
            ? Carbon::parse((string) $data['ends_on'])->startOfDay()
            : $lastDue;

        if ($endsOn->lt($lastDue)) {
            throw new DomainException('The end date must not fall before the last recognition period.');
        }

        $isAccrual = in_array($kind, [AccountingSchedule::KIND_ACCRUED_EXPENSE, AccountingSchedule::KIND_ACCRUED_REVENUE], true);
        $reversalPolicy = (string) ($data['reversal_policy'] ?? ($isAccrual ? AccountingSchedule::REVERSAL_NEXT_PERIOD : AccountingSchedule::REVERSAL_NONE));
        if (! in_array($reversalPolicy, AccountingSchedule::REVERSAL_POLICIES, true)) {
            throw new DomainException('Choose a valid reversal policy.');
        }
        if (! $isAccrual && $reversalPolicy !== AccountingSchedule::REVERSAL_NONE) {
            throw new DomainException('Automatic reversal only applies to accrued expenses and revenues.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $this->settings->baseCurrency()));
        if (strlen($currency) !== 3) {
            throw new DomainException('Currency must be a 3-letter code.');
        }

        $base = $this->settings->baseCurrency();
        $date = $start->toDateString();
        $rate = $currency === $base
            ? '1'
            : (isset($data['fx_rate']) && $data['fx_rate'] !== '' ? (string) $data['fx_rate'] : $this->fx->rateFor($currency, $date));

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new DomainException('The FX rate must be greater than zero.');
        }

        $sourceCode = (string) ($data['source_account_code'] ?? $this->defaultSourceCode($kind));
        $targetCode = (string) ($data['target_account_code'] ?? $this->defaultTargetCode($kind));
        if ($targetCode === '') {
            throw new DomainException('Choose the expense or revenue account this schedule recognizes into.');
        }

        $source = $this->posting->account($sourceCode);
        $target = $this->posting->account($targetCode);
        if ($source->id === $target->id) {
            throw new DomainException('The source and recognition accounts must be different.');
        }

        return [
            'kind' => $kind,
            'description' => (string) $data['description'],
            'reference' => $data['reference'] ?? null,
            'currency_code' => $currency,
            'fx_rate' => $rate,
            'fx_rate_date' => $date,
            'fx_rate_source' => $currency === $base ? 'manual' : 'market',
            'total_minor' => $total,
            'periods' => $periods,
            'frequency' => $frequency,
            'starts_on' => $date,
            'ends_on' => $endsOn->toDateString(),
            'source_account_id' => $source->id,
            'target_account_id' => $target->id,
            'reversal_policy' => $reversalPolicy,
        ];
    }

    /** @return array<string, mixed> */
    private function editable(AccountingSchedule $schedule): array
    {
        return [
            'kind' => $schedule->kind,
            'description' => $schedule->description,
            'reference' => $schedule->reference,
            'currency' => $schedule->currency_code,
            'fx_rate' => (string) $schedule->fx_rate,
            'total_minor' => $schedule->total_minor,
            'periods' => $schedule->periods,
            'frequency' => $schedule->frequency,
            'starts_on' => $schedule->starts_on->toDateString(),
            'source_account_code' => $schedule->sourceAccount->code,
            'target_account_code' => $schedule->targetAccount->code,
            'reversal_policy' => $schedule->reversal_policy,
        ];
    }

    private function defaultSourceCode(string $kind): string
    {
        return match ($kind) {
            AccountingSchedule::KIND_PREPAID_EXPENSE => (string) $this->settings->get('accounting', 'prepaid_expense_account_code', '1150'),
            AccountingSchedule::KIND_DEFERRED_REVENUE => (string) $this->settings->get('accounting', 'deferred_revenue_account_code', '2400'),
            AccountingSchedule::KIND_ACCRUED_EXPENSE => (string) $this->settings->get('accounting', 'accrued_liability_account_code', '2100'),
            AccountingSchedule::KIND_ACCRUED_REVENUE => (string) $this->settings->get('accounting', 'accrued_revenue_account_code', '1100'),
        };
    }

    private function defaultTargetCode(string $kind): string
    {
        return match ($kind) {
            AccountingSchedule::KIND_DEFERRED_REVENUE,
            AccountingSchedule::KIND_ACCRUED_REVENUE => (string) $this->settings->get('accounting', 'revenue_account_code', '4100'),
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(AccountingScheduleEvent $event): array
    {
        return [
            'id' => $event->public_id,
            'schedule_id' => $event->schedule?->public_id,
            'schedule_number' => $event->schedule?->number,
            'description' => $event->schedule?->description,
            'branch_id' => $event->schedule?->branch?->public_id,
            'kind' => $event->kind,
            'status' => $event->status,
            'due_date' => $event->due_date?->toDateString(),
            'amount_minor' => $event->amount_minor,
            'currency' => $event->schedule?->currency_code,
            'failure_reason' => $event->failure_reason,
            'journal_entry_id' => $event->journalEntry?->public_id,
        ];
    }
}
