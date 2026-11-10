<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of the general ledger (ADR-0004).
 *
 * Every automatic and manual posting goes through post(); no module may
 * insert journal rows directly. Posted entries are immutable and corrections
 * are made with reverse().
 */
class PostingService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly SequenceService $sequences,
        private readonly BranchScope $branchScope,
        private readonly FxService $fx,
        private readonly AuditService $audit,
    ) {}

    public function post(PostingRequest $request): JournalEntry
    {
        return $this->persist($request, 'posted');
    }

    /**
     * Store a balanced entry without posting it. A draft can be reviewed and
     * later posted through postDraft() (FR-FIN-003).
     */
    public function draft(PostingRequest $request): JournalEntry
    {
        return $this->persist($request, 'draft');
    }

    public function postDraft(JournalEntry $entry, ?int $actorId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actorId) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== 'draft') {
                throw new DomainException('Only draft journal entries can be posted.');
            }

            $this->assertActorMayPost(new PostingRequest(
                entryDate: $entry->entry_date->toDateString(),
                branch: $entry->branch,
                currency: $entry->currency_code,
                fxRate: (string) $entry->fx_rate,
                fxRateDate: $entry->fx_rate_date->toDateString(),
                fxRateSource: $entry->fx_rate_source,
                lines: [],
                actorId: $actorId,
            ));

            $period = $this->resolveOpenPeriod($entry->entry_date->toDateString(), $actorId);

            $entry->update([
                'status' => 'posted',
                'fiscal_period_id' => $period->id,
                'posted_at' => now(),
                'posted_by' => $actorId,
            ]);

            $this->audit->log('journal.posted', $entry, ['status' => 'draft'], [
                'status' => 'posted',
                'number' => $entry->number,
            ], $actorId);

            return $entry->refresh()->load('lines.account');
        });
    }

    private function persist(PostingRequest $request, string $status): JournalEntry
    {
        return DB::transaction(function () use ($request, $status) {
            $this->assertLinesValid($request);
            $this->assertNoDuplicateSource($request);
            $this->assertActorMayPost($request);

            $period = $this->resolveOpenPeriod($request->entryDate, $request->actorId);
            $baseCurrency = $this->settings->baseCurrency();

            $prepared = $this->prepareLines($request, $baseCurrency);

            $entry = new JournalEntry([
                'number' => $this->sequences->next('journal', $request->branch, Carbon::parse($request->entryDate)),
                'entry_date' => $request->entryDate,
                'branch_id' => $request->branch->id,
                'fiscal_period_id' => $period->id,
                'currency_code' => $request->currency,
                'fx_rate' => $request->fxRate,
                'fx_rate_date' => $request->fxRateDate,
                'fx_rate_source' => $request->fxRateSource,
                'status' => $status,
                'memo' => $request->memo,
                'source_type' => $request->sourceType,
                'source_id' => $request->sourceId,
                'event' => $request->event,
                'posted_at' => $status === 'posted' ? now() : null,
                'posted_by' => $status === 'posted' ? $request->actorId : null,
                'created_by' => $request->actorId,
            ]);
            $entry->save();

            foreach ($prepared as $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account']->id,
                    'branch_id' => $request->branch->id,
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'base_debit_minor' => $line['base_debit_minor'],
                    'base_credit_minor' => $line['base_credit_minor'],
                    'currency_code' => $request->currency,
                    'party_type' => $line['party_type'],
                    'party_id' => $line['party_id'],
                    'description' => $line['description'],
                ]);
            }

            $this->audit->log($status === 'posted' ? 'journal.posted' : 'journal.drafted', $entry, null, [
                'number' => $entry->number,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'event' => $entry->event,
                'total_minor' => $this->transactionTotal($prepared),
            ], $request->actorId);

            return $entry->refresh()->load('lines.account');
        });
    }

    /**
     * Reverse a posted entry exactly once. Reversing a reversal is rejected:
     * create a new correction instead (ADR-0004 §5).
     */
    public function reverse(JournalEntry $entry, string $reason, ?int $actorId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $actorId) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== 'posted') {
                throw new DomainException('Only posted entries can be reversed.');
            }

            if ($entry->reversal_of_id !== null) {
                throw new DomainException('A reversal entry cannot itself be reversed; post a new correction instead.');
            }

            if (JournalEntry::query()->where('reversal_of_id', $entry->id)->exists()) {
                throw new DomainException('This entry has already been reversed.');
            }

            $this->assertActorMayPost(new PostingRequest(
                entryDate: $entry->entry_date->toDateString(),
                branch: $entry->branch,
                currency: $entry->currency_code,
                fxRate: (string) $entry->fx_rate,
                fxRateDate: $entry->fx_rate_date->toDateString(),
                fxRateSource: $entry->fx_rate_source,
                lines: [],
                actorId: $actorId,
            ));

            $period = $this->resolveOpenPeriod($entry->entry_date->toDateString(), $actorId);

            $reversal = new JournalEntry([
                'number' => $this->sequences->next('journal', $entry->branch, $entry->entry_date),
                'entry_date' => $entry->entry_date,
                'branch_id' => $entry->branch_id,
                'fiscal_period_id' => $period->id,
                'currency_code' => $entry->currency_code,
                'fx_rate' => $entry->fx_rate,
                'fx_rate_date' => $entry->fx_rate_date,
                'fx_rate_source' => $entry->fx_rate_source,
                'status' => 'posted',
                'memo' => 'Reversal: '.$reason,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                // The unique (source_type, source_id, event) key must stay unique,
                // so the reversal carries a derived event key while keeping the
                // same source reference for traceability.
                'event' => $entry->event !== null ? $entry->event.'.reversal' : null,
                'reversal_of_id' => $entry->id,
                'reversal_reason' => $reason,
                'posted_at' => now(),
                'posted_by' => $actorId,
                'created_by' => $actorId,
            ]);
            $reversal->save();

            foreach ($entry->lines as $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'branch_id' => $line->branch_id,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'base_debit_minor' => $line->base_credit_minor,
                    'base_credit_minor' => $line->base_debit_minor,
                    'currency_code' => $line->currency_code,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'description' => 'Reversal of '.$entry->number,
                ]);
            }

            $this->audit->log('journal.reversed', $reversal, ['entry' => $entry->public_id], [
                'reversal' => $reversal->public_id,
                'reason' => $reason,
            ], $actorId);

            return $reversal->refresh()->load('lines.account');
        });
    }

    public function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if (! $account) {
            throw new DomainException("Account {$code} is not configured. Complete the chart of accounts before posting.");
        }

        return $account;
    }

    /** @param PostingLine[] $lines */
    private function assertLinesValid(PostingRequest $request): void
    {
        if (count($request->lines) < 2) {
            throw new DomainException('A journal entry needs at least two lines.');
        }

        $debit = 0;
        $credit = 0;

        foreach ($request->lines as $line) {
            if (! $line instanceof PostingLine) {
                throw new DomainException('Invalid posting line.');
            }

            $hasDebit = $line->debitMinor > 0;
            $hasCredit = $line->creditMinor > 0;

            if ($hasDebit === $hasCredit) {
                throw new DomainException('Each line must have exactly one of debit or credit greater than zero.');
            }

            if ($line->debitMinor < 0 || $line->creditMinor < 0) {
                throw new DomainException('Debit and credit amounts cannot be negative.');
            }

            $account = $line->account;
            if (! $account->is_active || ! $account->is_postable) {
                throw new DomainException("Account {$account->code} is not active and postable.");
            }

            if ($account->currency_code !== null && strtoupper($account->currency_code) !== $request->currency) {
                throw new DomainException("Account {$account->code} only accepts {$account->currency_code}.");
            }

            $debit += $line->debitMinor;
            $credit += $line->creditMinor;
        }

        if ($debit !== $credit) {
            throw new DomainException("Journal entry is not balanced: debits {$debit} vs credits {$credit}.");
        }
    }

    private function assertNoDuplicateSource(PostingRequest $request): void
    {
        if ($request->sourceType === null || $request->sourceId === null || $request->event === null) {
            return;
        }

        $exists = JournalEntry::query()
            ->where('source_type', $request->sourceType)
            ->where('source_id', $request->sourceId)
            ->where('event', $request->event)
            ->exists();

        if ($exists) {
            throw new DomainException('This event has already been posted. Use a reversal to correct it.');
        }
    }

    private function assertActorMayPost(PostingRequest $request): void
    {
        // Accounting auto-posting remains disabled until the accountant
        // explicitly approves the accounting policy. This guards every
        // posting path (bookings, schedules, FX revaluation, documents).
        if ($this->settings->bool('accounting', 'accounting_policy_approved', false) === false) {
            throw new DomainException(
                'Accounting auto-posting is disabled until the accounting policy is approved by an accountant.'
            );
        }

        if ($request->actorId === null) {
            // System jobs may post only when policy is approved (checked above);
            // no per-user permission exists for machine identity.
            return;
        }

        $user = User::query()->find($request->actorId);
        if (! $user) {
            throw new DomainException('Actor not found.');
        }

        $this->branchScope->assertBranch($user, $request->branch);

        if (! $user->can('accounts.post')) {
            throw new DomainException('Actor does not have the accounts.post permission.');
        }
    }

    private function resolveOpenPeriod(string $entryDate, ?int $actorId): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate)
            ->first();

        if (! $period) {
            throw new DomainException("No fiscal period covers {$entryDate}. Create and open the period first.");
        }

        if ($period->status === 'open') {
            return $period;
        }

        if ($period->status === 'soft_closed' && $actorId !== null) {
            $user = User::query()->find($actorId);
            if ($user && $user->can('periods.post_soft_closed')) {
                return $period;
            }
        }

        throw new DomainException("Fiscal period {$period->name} is {$period->status}; posting is not allowed.");
    }

    /**
     * Convert every line to base currency and append the FX rounding residual
     * to the configured rounding account so the base entry balances exactly.
     *
     * @return array<int, array{account: Account, debit_minor: int, credit_minor: int, base_debit_minor: int, base_credit_minor: int, party_type: ?string, party_id: ?int, description: ?string}>
     */
    private function prepareLines(PostingRequest $request, string $baseCurrency): array
    {
        $prepared = [];
        $baseDebit = 0;
        $baseCredit = 0;

        foreach ($request->lines as $line) {
            $baseDebitMinor = (new Money($line->debitMinor, $request->currency))
                ->convertTo($baseCurrency, $request->fxRate)->amountMinor;
            $baseCreditMinor = (new Money($line->creditMinor, $request->currency))
                ->convertTo($baseCurrency, $request->fxRate)->amountMinor;

            $baseDebit += $baseDebitMinor;
            $baseCredit += $baseCreditMinor;

            $prepared[] = [
                'account' => $line->account,
                'debit_minor' => $line->debitMinor,
                'credit_minor' => $line->creditMinor,
                'base_debit_minor' => $baseDebitMinor,
                'base_credit_minor' => $baseCreditMinor,
                'party_type' => $line->partyType,
                'party_id' => $line->partyId,
                'description' => $line->description,
            ];
        }

        if ($baseDebit !== $baseCredit) {
            $difference = $baseDebit - $baseCredit;
            $rounding = $this->account((string) $this->settings->get('accounting', 'rounding_account_code', '6999'));

            $prepared[] = [
                'account' => $rounding,
                'debit_minor' => 0,
                'credit_minor' => 0,
                'base_debit_minor' => $difference < 0 ? abs($difference) : 0,
                'base_credit_minor' => $difference > 0 ? $difference : 0,
                'party_type' => null,
                'party_id' => null,
                'description' => 'FX rounding difference',
            ];
        }

        $finalDebit = array_sum(array_column($prepared, 'base_debit_minor'));
        $finalCredit = array_sum(array_column($prepared, 'base_credit_minor'));

        if ($finalDebit !== $finalCredit) {
            throw new DomainException("Base currency entry is not balanced: {$finalDebit} vs {$finalCredit}.");
        }

        return $prepared;
    }

    /** @param array<int, array{debit_minor: int}> $prepared */
    private function transactionTotal(array $prepared): int
    {
        return (int) array_sum(array_column($prepared, 'debit_minor'));
    }
}
