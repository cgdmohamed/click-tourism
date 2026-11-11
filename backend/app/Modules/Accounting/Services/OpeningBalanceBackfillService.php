<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Accounting\Models\OpeningBalanceLine;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Brings opening-balance batches posted before party subaccounts, opening
 * documents and opening credit records existed up to the current model.
 *
 * For every party line of a posted batch it (1) moves the amount from the
 * receivable/payable control account to the party subaccount (customer debit,
 * supplier credit) or to the advance account (customer credit, supplier
 * advance) with one balanced reclassification entry per batch and currency,
 * posted through PostingService, and (2) creates the matching opening
 * document / receipt / supplier advance, linked to the batch line. Safe to run
 * repeatedly: a line that is already in place and already has its record is
 * skipped. All writes run as the system (actor null).
 */
class OpeningBalanceBackfillService
{
    public const SOURCE_TYPE = 'opening_balance_backfill';

    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AuditService $audit,
        private readonly OpeningBalanceService $opening,
    ) {}

    /**
     * @return array{applied: bool, batches: array<int, array<string, mixed>>, entries: string[], records: int, errors: string[]}
     */
    public function run(bool $apply, ?string $branchCode = null, ?int $limit = null): array
    {
        if (! $this->settings->bool('accounting', 'accounting_policy_approved', false)) {
            throw new DomainException('Refusing to run: accounting_policy_approved is false. The accountant must approve the policy first.');
        }

        $result = ['applied' => $apply, 'batches' => [], 'entries' => [], 'records' => 0, 'errors' => []];

        $query = OpeningBalanceBatch::query()->with('branch')->where('status', 'posted')->orderBy('id');
        if ($branchCode !== null && $branchCode !== '') {
            $query->whereHas('branch', fn ($q) => $q->where('code', $branchCode));
        }

        $processed = 0;
        foreach ($query->cursor() as $batch) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            try {
                if ($apply) {
                    $report = DB::transaction(function () use ($batch) {
                        $locked = OpeningBalanceBatch::query()->with('branch')->lockForUpdate()->findOrFail($batch->id);
                        $items = $this->plan($locked);

                        return $items === [] ? null : $this->applyBatch($locked, $items);
                    });
                } else {
                    // Resolving a target may lazily create a party subaccount; a dry run keeps nothing.
                    DB::beginTransaction();
                    try {
                        $items = $this->plan($batch);
                        $report = $items === [] ? null : $this->describe($batch, $items);
                    } finally {
                        DB::rollBack();
                    }
                }
            } catch (DomainException $e) {
                $result['errors'][] = "Batch {$batch->public_id}: ".$e->getMessage();

                continue;
            }

            if ($report === null) {
                continue;
            }

            $processed++;
            $result['batches'][] = $report['batch'];
            $result['entries'] = array_merge($result['entries'], $report['entries']);
            $result['records'] += $report['records'];
        }

        return $result;
    }

    /**
     * The work still to do for a batch: one item per party line.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plan(OpeningBalanceBatch $batch): array
    {
        $batch->load('lines.account');
        $arCode = (string) $this->settings->get('accounting', 'ar_account_code');
        $apCode = (string) $this->settings->get('accounting', 'ap_account_code');
        $entries = $batch->entries()->get();
        $entryByCurrency = $entries->mapWithKeys(fn ($entry) => [$entry->pivot->currency_code => $entry->id])->all();
        $entryIds = $entries->pluck('id')->all();

        // Currencies already reclassified by an earlier run: the original entry
        // stays as posted, so its control-account lines must not be moved twice.
        $reclassified = JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)->where('source_id', $batch->id)
            ->whereNull('reversal_of_id')->pluck('event')
            ->map(fn ($event) => substr((string) $event, strrpos((string) $event, ':') + 1))->all();

        // Party-tagged lines of the batch's own entries; the ones not yet on
        // their target account are the legacy shape (control account, or the
        // party subaccount for credit-side lines that never reached an advance account).
        $onControl = JournalLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->whereNotNull('party_id')
            ->get()
            ->map(fn (JournalLine $line) => ['line' => $line, 'used' => false])
            ->all();
        $subaccounts = [];

        $adopted = [];
        $items = [];
        foreach ($batch->lines as $line) {
            $kind = $this->opening->kind($line, $arCode, $apCode);
            if ($kind === null) {
                continue;
            }

            $target = $this->opening->targetAccount($line);
            $partyKey = $line->party_type.'|'.$line->party_id;
            $subaccounts[$partyKey] ??= PartyAccount::query()
                ->where('party_type', $line->party_type)->where('party_id', $line->party_id)
                ->pluck('account_id')->map(fn ($id) => (int) $id)->all();

            $fromControl = false;
            $fromAccountId = null;
            foreach ($onControl as $i => $candidate) {
                $journal = $candidate['line'];
                if (! $candidate['used']
                    && (int) $journal->account_id !== (int) $target->id
                    && ((int) $journal->account_id === (int) $line->account_id
                        || in_array((int) $journal->account_id, $subaccounts[$partyKey], true))
                    && $journal->party_type === $line->party_type
                    && (int) $journal->party_id === (int) $line->party_id
                    && $journal->currency_code === $line->currency_code
                    && ! in_array($line->currency_code, $reclassified, true)
                    && (int) $journal->debit_minor === (int) $line->debit_minor
                    && (int) $journal->credit_minor === (int) $line->credit_minor) {
                    $onControl[$i]['used'] = true;
                    $fromControl = true;
                    $fromAccountId = (int) $journal->account_id;
                    break;
                }
            }

            $hasRecord = $this->recordExists($kind, $line);
            $adoptable = null;
            if (! $hasRecord && in_array($kind, ['customer_document', 'supplier_document'], true)) {
                // A document created before the batch line was recorded on it.
                $adoptable = Invoice::query()
                    ->where('is_opening', true)
                    ->whereNull('opening_balance_line_id')
                    ->where('party_type', $line->party_type)->where('party_id', $line->party_id)
                    ->where('currency_code', $line->currency_code)
                    ->where('total_minor', $kind === 'customer_document' ? $line->debit_minor : $line->credit_minor)
                    ->whereIn('journal_entry_id', $entryIds)
                    ->whereNotIn('id', $adopted)
                    ->orderBy('id')->first();
                if ($adoptable) {
                    $adopted[] = $adoptable->id;
                }
            }

            if (! $fromControl && $hasRecord) {
                continue;
            }

            $items[] = [
                'line' => $line,
                'kind' => $kind,
                'from_control' => $fromControl,
                'from_account_id' => $fromAccountId,
                'needs_record' => ! $hasRecord && $adoptable === null,
                'adopt' => $adoptable,
                'original_entry_id' => $entryByCurrency[$line->currency_code] ?? null,
            ];
        }

        return $items;
    }

    private function recordExists(string $kind, OpeningBalanceLine $line): bool
    {
        return match ($kind) {
            'customer_document', 'supplier_document' => Invoice::query()->where('opening_balance_line_id', $line->id)->exists(),
            'customer_credit' => Payment::query()->where('opening_balance_line_id', $line->id)->exists(),
            'supplier_advance' => SupplierPayment::query()->where('opening_balance_line_id', $line->id)->exists(),
            default => true,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{batch: array<string, mixed>, entries: string[], records: int}
     */
    private function describe(OpeningBalanceBatch $batch, array $items): array
    {
        return [
            'batch' => [
                'id' => $batch->public_id,
                'branch' => $batch->branch->code,
                'as_of_date' => $batch->as_of_date->toDateString(),
                'entry_date' => $this->entryDate($batch),
                'lines' => array_map(fn ($item) => $this->row($item), $items),
            ],
            'entries' => [],
            'records' => count(array_filter($items, fn ($i) => $i['needs_record'])),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{batch: array<string, mixed>, entries: string[], records: int}
     */
    private function applyBatch(OpeningBalanceBatch $batch, array $items): array
    {
        $date = $this->entryDate($batch);
        $asOf = $batch->as_of_date->toDateString();
        $entryNumbers = [];
        $reclassEntry = [];

        $byCurrency = [];
        foreach ($items as $item) {
            if ($item['from_control']) {
                $byCurrency[$item['line']->currency_code][] = $item;
            }
        }

        foreach ($byCurrency as $currency => $group) {
            $lines = [];
            foreach ($group as $item) {
                /** @var OpeningBalanceLine $line */
                $line = $item['line'];
                $rate = (string) $line->fx_rate;
                $target = $this->opening->targetAccount($line);
                $from = (int) $item['from_account_id'] === (int) $line->account_id
                    ? $line->account
                    : Account::query()->findOrFail($item['from_account_id']);
                $label = 'Opening balance reclassification';
                $debit = (int) $line->debit_minor > 0;
                $amount = $debit ? (int) $line->debit_minor : (int) $line->credit_minor;
                // The party keeps the same side; the control account takes the opposite.
                $targetLine = $debit
                    ? PostingLine::debit($target, $amount, $line->party_type, (int) $line->party_id, $label)
                    : PostingLine::credit($target, $amount, $line->party_type, (int) $line->party_id, $label);
                $controlLine = $debit
                    ? PostingLine::credit($from, $amount, $line->party_type, (int) $line->party_id, $label)
                    : PostingLine::debit($from, $amount, $line->party_type, (int) $line->party_id, $label);
                array_push($lines, $controlLine->in($currency, $rate), $targetLine->in($currency, $rate));
            }

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $batch->branch,
                currency: $currency,
                lines: $lines,
                memo: 'Opening balance reclassification '.$asOf.' ('.$currency.')',
                sourceType: self::SOURCE_TYPE,
                sourceId: $batch->id,
                event: 'backfill:'.$batch->id.':'.$currency,
                fxRate: (string) $group[0]['line']->fx_rate,
                fxRateDate: $asOf,
                fxRateSource: 'opening_balance',
                actorId: null,
            ));
            $reclassEntry[$currency] = $entry->id;
            $entryNumbers[] = $entry->number;
        }

        $records = 0;
        foreach ($items as $item) {
            $line = $item['line'];
            if ($item['adopt'] instanceof Invoice) {
                $item['adopt']->update(['opening_balance_line_id' => $line->id]);

                continue;
            }
            if (! $item['needs_record']) {
                continue;
            }
            $entryId = $reclassEntry[$line->currency_code] ?? $item['original_entry_id'];
            if ($entryId === null) {
                throw new DomainException("Batch {$batch->public_id} has no posted entry for {$line->currency_code}.");
            }
            $this->opening->createOpeningRecord($batch, $line, (int) $entryId, null);
            $records++;
        }

        $this->audit->log('opening_balance.backfilled', $batch, null, [
            'entries' => $entryNumbers,
            'lines' => count($items),
            'records' => $records,
        ], null);

        return [
            'batch' => [
                'id' => $batch->public_id,
                'branch' => $batch->branch->code,
                'as_of_date' => $asOf,
                'entry_date' => $date,
                'lines' => array_map(fn ($item) => $this->row($item), $items),
            ],
            'entries' => $entryNumbers,
            'records' => $records,
        ];
    }

    /** The batch date when its period is still open, otherwise today (the closed-period rule). */
    private function entryDate(OpeningBalanceBatch $batch): string
    {
        $asOf = $batch->as_of_date->toDateString();
        $period = FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $asOf)->whereDate('ends_on', '>=', $asOf)->first();

        return $period && $period->status === 'open' ? $asOf : now()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function row(array $item): array
    {
        /** @var OpeningBalanceLine $line */
        $line = $item['line'];
        $target = $this->opening->targetAccount($line);

        return [
            'party' => $line->party_type.'#'.$line->party_id,
            'currency' => $line->currency_code,
            'side' => (int) $line->debit_minor > 0 ? 'debit' : 'credit',
            'amount_minor' => (int) $line->debit_minor > 0 ? (int) $line->debit_minor : (int) $line->credit_minor,
            'kind' => $item['kind'],
            'from' => $item['from_control'] ? Account::query()->whereKey($item['from_account_id'])->value('code') : '-',
            'to' => $item['from_control'] ? $target->code : '-',
            'record' => $item['needs_record'] ? 'create' : ($item['adopt'] ? 'link' : '-'),
        ];
    }
}
