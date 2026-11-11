<?php

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingDocumentService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Models\BankReconciliationMatch;
use App\Modules\Treasury\Models\BankStatement;
use App\Modules\Treasury\Models\BankStatementLine;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

/**
 * Bank reconciliation (Prompt 23, TE-023).
 *
 * Imports external bank statements (CSV/XLSX with a per-bank column mapping)
 * and links statement lines to existing posted journal lines. It never writes
 * to the ledger: matching only records links, and the only ledger effect is
 * an adjustment voucher explicitly proposed by a user, which then flows
 * through the normal document approval/posting workflow.
 */
class BankReconciliationService
{
    public const MATCH_KIND_AUTO = 'auto';

    public const MATCH_KIND_MANUAL = 'manual';

    public const CATEGORY_OUTSTANDING_PAYMENT = 'outstanding_payment';

    public const CATEGORY_DEPOSIT_IN_TRANSIT = 'deposit_in_transit';

    public const CATEGORY_UNRECORDED_BANK_DEBIT = 'unrecorded_bank_debit';

    public const CATEGORY_UNRECORDED_BANK_CREDIT = 'unrecorded_bank_credit';

    public const CATEGORY_TOLERANCE = 'tolerance_difference';

    public function __construct(
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
        private readonly AccountingDocumentService $documents,
    ) {}

    // ------------------------------------------------------------------
    // Statement import
    // ------------------------------------------------------------------

    /**
     * Import a bank statement file.
     *
     * @param  array<string, mixed>  $data
     * @return array{statement: BankStatement, imported_lines: int, skipped_duplicates: int}
     */
    public function import(UploadedFile $file, array $data, ?Branch $branch, ?int $actorId): array
    {
        return DB::transaction(function () use ($file, $data, $branch, $actorId) {
            $cashAccount = $this->cashAccount((string) ($data['cash_account_id'] ?? ''));
            $this->assertBranchAccess($cashAccount, $actorId);
            $currency = strtoupper($cashAccount->currency_code);

            $format = strtolower($file->getClientOriginalExtension() ?: 'csv');
            $content = (string) file_get_contents($file->getRealPath());
            if ($content === '') {
                throw new DomainException('The uploaded statement file is empty.');
            }

            $fileHash = hash('sha256', $content);
            $alreadyImported = BankStatement::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('file_hash', $fileHash)
                ->exists();

            if ($alreadyImported) {
                throw new DomainException('This statement file has already been imported for this bank account.');
            }

            $rows = $this->readRows($content, $format, (string) ($data['delimiter'] ?? ','));
            if (count($rows) < 2) {
                throw new DomainException('The statement has no data rows.');
            }

            $header = array_shift($rows);
            $mapping = $this->normalizeMapping($data['mapping'] ?? [], $header);
            $columns = $this->resolveColumns($mapping, $header);
            $dateFormat = isset($mapping['date_format']) ? (string) $mapping['date_format'] : null;

            $parsed = [];
            $seenHashes = [];
            foreach ($rows as $index => $row) {
                $line = $this->parseStatementRow($row, $columns, $dateFormat, $currency, $cashAccount, $seenHashes);
                if ($line !== null) {
                    $line['row_number'] = $index + 2;
                    $parsed[] = $line;
                }
            }

            if ($parsed === []) {
                throw new DomainException('No usable transaction rows were found in the statement.');
            }

            $existingHashes = BankStatementLine::query()
                ->where('cash_account_id', $cashAccount->id)
                ->whereIn('line_hash', array_column($parsed, 'line_hash'))
                ->pluck('line_hash')
                ->all();

            $existing = array_flip($existingHashes);
            $newLines = array_values(array_filter($parsed, fn (array $line) => ! isset($existing[$line['line_hash']])));
            $skipped = count($parsed) - count($newLines);

            if ($newLines === []) {
                throw new DomainException('Every transaction in this statement has already been imported.');
            }

            $dates = array_column($newLines, 'line_date');
            $from = isset($data['period_from']) && $data['period_from'] !== null
                ? Carbon::parse((string) $data['period_from'])->toDateString()
                : min($dates);
            $to = isset($data['period_to']) && $data['period_to'] !== null
                ? Carbon::parse((string) $data['period_to'])->toDateString()
                : max($dates);

            if ($from > $to) {
                throw new DomainException('The statement period start must be on or before its end.');
            }

            $movement = (int) array_sum(array_column($newLines, 'amount_minor'));
            $opening = isset($data['opening_balance_minor']) && $data['opening_balance_minor'] !== null
                ? (int) $data['opening_balance_minor']
                : null;
            $closing = isset($data['closing_balance_minor']) && $data['closing_balance_minor'] !== null
                ? (int) $data['closing_balance_minor']
                : null;

            if ($opening === null && $closing === null) {
                $opening = 0;
                $closing = $movement;
            } elseif ($opening === null) {
                $opening = $closing - $movement;
            } elseif ($closing === null) {
                $closing = $opening + $movement;
            }

            $statement = BankStatement::query()->create([
                'cash_account_id' => $cashAccount->id,
                'branch_id' => $cashAccount->branch_id ?? $branch?->id,
                'imported_by' => $actorId,
                'filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'format' => $format,
                'period_from' => $from,
                'period_to' => $to,
                'opening_balance_minor' => $opening,
                'closing_balance_minor' => $closing,
                'currency_code' => $currency,
                'line_count' => count($newLines),
                'mapping' => $mapping,
            ]);

            foreach ($newLines as $line) {
                BankStatementLine::query()->create([
                    'bank_statement_id' => $statement->id,
                    'cash_account_id' => $cashAccount->id,
                    'line_hash' => $line['line_hash'],
                    'line_date' => $line['line_date'],
                    'value_date' => $line['value_date'],
                    'amount_minor' => $line['amount_minor'],
                    'balance_after_minor' => $line['balance_after_minor'],
                    'bank_reference' => $line['bank_reference'],
                    'description' => $line['description'],
                    'party_name' => $line['party_name'],
                    'raw' => $line['raw'],
                ]);
            }

            $this->audit->log('bank_statement.imported', $statement, null, [
                'filename' => $statement->filename,
                'lines' => count($newLines),
                'skipped_duplicates' => $skipped,
                'opening_balance_minor' => $opening,
                'closing_balance_minor' => $closing,
            ], $actorId);

            return [
                'statement' => $statement->refresh()->load('lines'),
                'imported_lines' => count($newLines),
                'skipped_duplicates' => $skipped,
            ];
        });
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSession(array $data, ?Branch $branch, ?int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($data, $branch, $actorId) {
            $cashAccount = $this->cashAccount((string) ($data['cash_account_id'] ?? ''));
            $this->assertBranchAccess($cashAccount, $actorId);

            $currency = strtoupper($cashAccount->currency_code);
            if ($currency !== $this->settings->baseCurrency()) {
                throw new DomainException(
                    'Bank reconciliation is limited to base-currency bank accounts. '.
                    'Foreign-currency bank reconciliation is pending an accountant decision (DEC-037).'
                );
            }

            $statement = null;
            if (! empty($data['statement_id'])) {
                $statement = BankStatement::query()->where('public_id', (string) $data['statement_id'])->first();
                if (! $statement) {
                    throw new DomainException('The selected bank statement does not exist.');
                }
                if ($statement->cash_account_id !== $cashAccount->id) {
                    throw new DomainException('The selected statement belongs to another bank account.');
                }
            }

            $from = $data['period_from'] ?? $statement?->period_from?->toDateString();
            $to = $data['period_to'] ?? $statement?->period_to?->toDateString();
            if (empty($from) || empty($to)) {
                throw new DomainException('A reconciliation period start and end are required.');
            }
            $from = Carbon::parse((string) $from)->toDateString();
            $to = Carbon::parse((string) $to)->toDateString();
            if ($from > $to) {
                throw new DomainException('The reconciliation period start must be on or before its end.');
            }

            $openingBook = $this->bookOpeningBalance($cashAccount, $from);

            $statementMovement = (int) BankStatementLine::query()
                ->where('cash_account_id', $cashAccount->id)
                ->whereDate('line_date', '>=', $from)
                ->whereDate('line_date', '<=', $to)
                ->sum('amount_minor');

            $openingBank = isset($data['opening_bank_balance_minor']) && $data['opening_bank_balance_minor'] !== null
                ? (int) $data['opening_bank_balance_minor']
                : (int) ($statement?->opening_balance_minor ?? 0);
            $closingBank = isset($data['closing_bank_balance_minor']) && $data['closing_bank_balance_minor'] !== null
                ? (int) $data['closing_bank_balance_minor']
                : (int) ($statement?->closing_balance_minor ?? ($openingBank + $statementMovement));

            $reconciliation = BankReconciliation::query()->create([
                'cash_account_id' => $cashAccount->id,
                'branch_id' => $cashAccount->branch_id ?? $branch?->id,
                'bank_statement_id' => $statement?->id,
                'period_from' => $from,
                'period_to' => $to,
                'currency_code' => $currency,
                'opening_book_balance_minor' => $openingBook,
                'opening_bank_balance_minor' => $openingBank,
                'closing_bank_balance_minor' => $closingBank,
                'closing_book_balance_minor' => $openingBook,
                'status' => BankReconciliation::STATUS_OPEN,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->audit->log('bank_reconciliation.created', $reconciliation, null, [
                'period_from' => $from,
                'period_to' => $to,
                'opening_book_balance_minor' => $openingBook,
                'closing_bank_balance_minor' => $closingBank,
            ], $actorId);

            return $reconciliation->refresh()->load('cashAccount.account', 'branch');
        });
    }

    // ------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------

    /**
     * Auto-match unambiguous one-to-one candidates within tolerance/window.
     *
     * @return array{created: int, ambiguous: int, remaining: int}
     */
    public function autoMatch(BankReconciliation $reconciliation, ?int $actorId): array
    {
        return DB::transaction(function () use ($reconciliation, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertNotApproved($reconciliation);

            $detail = $this->compute($reconciliation);
            $candidates = $detail['candidates'];
            $bankPks = $candidates === []
                ? collect()
                : BankStatementLine::query()
                    ->whereIn('public_id', array_column($candidates, 'bank_line_id'))
                    ->pluck('id', 'public_id');
            $bookPks = $candidates === []
                ? collect()
                : JournalLine::query()
                    ->whereIn('public_id', array_column($candidates, 'journal_line_id'))
                    ->pluck('id', 'public_id');
            $usedBank = [];
            $usedBook = [];
            $created = 0;
            $ambiguous = 0;

            foreach ($candidates as $candidate) {
                if ($candidate['ambiguous']) {
                    $ambiguous++;

                    continue;
                }
                if (isset($usedBank[$candidate['bank_line_id']]) || isset($usedBook[$candidate['journal_line_id']])) {
                    continue;
                }

                $bankPk = $bankPks[$candidate['bank_line_id']] ?? null;
                $bookPk = $bookPks[$candidate['journal_line_id']] ?? null;
                if ($bankPk === null || $bookPk === null) {
                    continue;
                }

                BankReconciliationMatch::query()->create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'match_group' => (string) Str::ulid(),
                    'bank_statement_line_id' => $bankPk,
                    'journal_line_id' => $bookPk,
                    'amount_minor' => $candidate['amount_minor'],
                    'kind' => self::MATCH_KIND_AUTO,
                    'created_by' => $actorId,
                ]);

                $usedBank[$candidate['bank_line_id']] = true;
                $usedBook[$candidate['journal_line_id']] = true;
                $created++;
            }

            $this->audit->log('bank_reconciliation.auto_matched', $reconciliation, null, [
                'created' => $created,
                'ambiguous' => $ambiguous,
            ], $actorId);

            $fresh = $this->compute($reconciliation->refresh());
            $remaining = count(array_filter($fresh['bank_lines'], fn (array $line) => $line['remaining_minor'] > 0));

            return ['created' => $created, 'ambiguous' => $ambiguous, 'remaining' => $remaining];
        });
    }

    /**
     * Create an explicit match group. Items share the same `match_group` and
     * together must consume every involved line within the configured
     * tolerance, which prevents partial or double matching.
     *
     * @param  array<int, array{bank_line_id: string, journal_line_id: string, amount_minor: int}>  $items
     */
    public function match(BankReconciliation $reconciliation, array $items, ?int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $items, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertNotApproved($reconciliation);

            if ($items === []) {
                throw new DomainException('A match needs at least one bank line and one journal line.');
            }

            $bankLines = $this->statementLineQuery($reconciliation)->get()->keyBy('public_id');
            $journalLines = $this->bookLineQuery($reconciliation)->get()->keyBy('public_id');
            $bankConsumed = $this->consumedBank($bankLines->pluck('id')->all());
            $journalConsumed = $this->consumedJournal($journalLines->pluck('id')->all());
            $tolerance = $this->tolerance();

            $rows = [];
            $totals = [];
            $seenPairs = [];

            foreach ($items as $index => $item) {
                $position = $index + 1;
                $bank = $bankLines->get((string) ($item['bank_line_id'] ?? ''));
                if (! $bank) {
                    throw new DomainException("Item {$position}: the bank line is not part of this reconciliation period.");
                }
                $book = $journalLines->get((string) ($item['journal_line_id'] ?? ''));
                if (! $book) {
                    throw new DomainException("Item {$position}: the journal line is not part of this reconciliation period.");
                }

                $bookIsDebit = $book->base_debit_minor > 0;
                if (($bank->amount_minor >= 0) !== $bookIsDebit) {
                    throw new DomainException("Item {$position}: a money-in bank line must match a debit and a money-out line a credit.");
                }

                $amount = (int) ($item['amount_minor'] ?? 0);
                if ($amount <= 0) {
                    throw new DomainException("Item {$position}: the matched amount must be greater than zero.");
                }

                $pairKey = $bank->public_id.'|'.$book->public_id;
                if (isset($seenPairs[$pairKey])) {
                    throw new DomainException("Item {$position}: this bank and ledger line are already paired.");
                }
                $seenPairs[$pairKey] = true;

                $bankRemaining = abs($bank->amount_minor) - ($bankConsumed[$bank->id] ?? 0);
                $bookRemaining = ($bookIsDebit ? $book->base_debit_minor : $book->base_credit_minor) - ($journalConsumed[$book->id] ?? 0);
                if ($amount > $bankRemaining + $tolerance || $amount > $bookRemaining + $tolerance) {
                    throw new DomainException("Item {$position}: the amount exceeds the unmatched amount of the line.");
                }

                $totals['bank:'.$bank->id] = ($totals['bank:'.$bank->id] ?? 0) + $amount;
                $totals['book:'.$book->id] = ($totals['book:'.$book->id] ?? 0) + $amount;

                $rows[] = [
                    'bank_id' => $bank->id,
                    'journal_id' => $book->id,
                    'amount_minor' => $amount,
                ];
            }

            foreach ($totals as $key => $total) {
                [$type, $id] = explode(':', $key);
                if ($type === 'bank') {
                    $line = $bankLines->firstWhere('id', (int) $id);
                    $amount = abs($line->amount_minor) - ($bankConsumed[$line->id] ?? 0);
                } else {
                    $line = $journalLines->firstWhere('id', (int) $id);
                    $amount = ($line->base_debit_minor > 0 ? $line->base_debit_minor : $line->base_credit_minor)
                        - ($journalConsumed[$line->id] ?? 0);
                }

                if (abs($amount - $total) > $tolerance) {
                    throw new DomainException(
                        'A match must consume each selected line in full (tolerance '.$tolerance.' minor units). '.
                        'Adjust the amounts or match the remaining part separately.'
                    );
                }
            }

            $group = (string) Str::ulid();
            foreach ($rows as $row) {
                BankReconciliationMatch::query()->create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'match_group' => $group,
                    'bank_statement_line_id' => $row['bank_id'],
                    'journal_line_id' => $row['journal_id'],
                    'amount_minor' => $row['amount_minor'],
                    'kind' => self::MATCH_KIND_MANUAL,
                    'created_by' => $actorId,
                ]);
            }

            $this->audit->log('bank_reconciliation.matched', $reconciliation, null, [
                'group' => $group,
                'rows' => count($rows),
                'amount_minor' => array_sum(array_column($rows, 'amount_minor')),
            ], $actorId);

            return $reconciliation->refresh();
        });
    }

    public function unmatch(BankReconciliation $reconciliation, string $group, ?int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $group, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertNotApproved($reconciliation);

            $matches = BankReconciliationMatch::query()
                ->where('bank_reconciliation_id', $reconciliation->id)
                ->where('match_group', $group)
                ->get();

            if ($matches->isEmpty()) {
                throw new DomainException('The match does not exist in this reconciliation.');
            }

            $amount = (int) $matches->sum('amount_minor');
            BankReconciliationMatch::query()->whereIn('id', $matches->pluck('id'))->delete();

            $this->audit->log('bank_reconciliation.unmatched', $reconciliation, [
                'group' => $group,
                'amount_minor' => $amount,
            ], null, $actorId);

            return $reconciliation->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Difference explanation & adjustment vouchers
    // ------------------------------------------------------------------

    /**
     * Create a draft adjustment voucher (through the existing document
     * workflow) for unrecorded bank items. Nothing is posted here.
     *
     * @param  array<string, mixed>  $data
     * @return array{document: array<string, mixed>, reconciliation: array<string, mixed>}
     */
    public function proposeAdjustment(BankReconciliation $reconciliation, array $data, ?int $actorId): array
    {
        return DB::transaction(function () use ($reconciliation, $data, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertNotApproved($reconciliation);

            if ($reconciliation->branch_id === null) {
                throw new DomainException('A branch is required to create the adjustment voucher.');
            }

            $detail = $this->compute($reconciliation);
            $selected = $data['bank_line_ids'] ?? null;
            $bankLines = collect($detail['bank_lines']);
            if (is_array($selected) && $selected !== []) {
                $bankLines = $bankLines->whereIn('id', $selected);
            }

            $items = [];
            $dates = [];
            foreach ($bankLines as $line) {
                $residual = (int) $line['remaining_minor'];
                if ($residual <= 0) {
                    continue;
                }
                if (! empty($line['line_date'])) {
                    $dates[] = (string) $line['line_date'];
                }
                $items[] = [
                    'direction' => $line['amount_minor'] >= 0 ? 'in' : 'out',
                    'amount_minor' => $residual,
                    'description' => trim(($line['description'] ?? 'Unrecorded bank item').' · '.($line['line_date'] ?? '')),
                ];
            }

            if ($items === []) {
                throw new DomainException('There are no unrecorded bank items to adjust.');
            }

            $cashAccount = $reconciliation->cashAccount;
            $bankAccount = $cashAccount->account->public_id;
            $charges = (string) ($data['charges_account_code'] ?? $this->settings->get('accounting', 'bank_charges_account_code', '6500'));
            $interest = (string) ($data['interest_account_code'] ?? $this->settings->get('accounting', 'bank_interest_account_code', '7920'));

            if (in_array('in', array_column($items, 'direction'), true)
                && ! Account::query()->where('code', $interest)->exists()) {
                throw new DomainException("Bank interest account {$interest} does not exist; run accounting:ensure-accounts --apply");
            }

            $lines = [];
            foreach ($items as $item) {
                if ($item['direction'] === 'in') {
                    $lines[] = ['account_id' => $bankAccount, 'debit_minor' => $item['amount_minor'], 'description' => $item['description']];
                    $lines[] = ['account_id' => $interest, 'credit_minor' => $item['amount_minor'], 'description' => $item['description']];
                } else {
                    $lines[] = ['account_id' => $charges, 'debit_minor' => $item['amount_minor'], 'description' => $item['description']];
                    $lines[] = ['account_id' => $bankAccount, 'credit_minor' => $item['amount_minor'], 'description' => $item['description']];
                }
            }

            $document = $this->documents->create([
                'type' => AccountingDocument::TYPE_ADJUSTMENT_VOUCHER,
                // Date the voucher on the bank transaction (oldest selected
                // item) so it can be matched within the date window; a user
                // may override it explicitly.
                'document_date' => (string) ($data['document_date'] ?? ($dates === [] ? $reconciliation->period_to->toDateString() : min($dates))),
                'currency' => $reconciliation->currency_code,
                'cash_account_id' => $cashAccount->public_id,
                'memo' => (string) ($data['memo'] ?? ('Bank reconciliation '.$reconciliation->public_id.' — unrecorded bank items')),
                'lines' => $lines,
                'submit' => (bool) ($data['submit'] ?? false),
            ], Branch::query()->findOrFail($reconciliation->branch_id), $actorId);

            $ids = $reconciliation->adjustment_document_ids ?? [];
            $ids[] = $document->public_id;
            $reconciliation->update(['adjustment_document_ids' => array_values(array_unique($ids))]);

            $this->audit->log('bank_reconciliation.adjustment_proposed', $reconciliation, null, [
                'document' => $document->public_id,
                'amount_minor' => array_sum(array_column($items, 'amount_minor')),
            ], $actorId);

            return [
                'document' => $document->load('lines.account')->toApiArray(true),
                'reconciliation' => $this->detail($reconciliation->refresh()),
            ];
        });
    }

    // ------------------------------------------------------------------
    // Approval (lock snapshot)
    // ------------------------------------------------------------------

    public function approve(BankReconciliation $reconciliation, ?int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);

            if ($reconciliation->isApproved()) {
                return $reconciliation;
            }

            $detail = $this->compute($reconciliation);
            $summary = $detail['summary'];

            // Unrecorded bank items must be resolved first: either matched to
            // an existing ledger line or posted as an adjustment voucher.
            // Outstanding book items (deposits in transit, unpresented checks)
            // may legitimately remain at approval time.
            if ($summary['unrecorded_bank_debits_minor'] > 0 || $summary['unrecorded_bank_credits_minor'] > 0) {
                throw new DomainException(
                    'The statement has unrecorded bank items. Match them or post their adjustment voucher before approving the reconciliation.'
                );
            }

            if (! $summary['reconciled']) {
                throw new DomainException(sprintf(
                    'The reconciliation is not balanced: the unexplained difference is %s %s (tolerance %s). Match or explain the remaining items first.',
                    number_format(abs($summary['difference_minor']) / 100, 2, '.', ','),
                    $reconciliation->currency_code,
                    number_format($summary['tolerance_minor'] / 100, 2, '.', ','),
                ));
            }

            $reconciliation->update([
                'status' => BankReconciliation::STATUS_APPROVED,
                'closing_book_balance_minor' => $summary['book_closing_minor'],
                'snapshot' => $detail,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            $this->audit->log('bank_reconciliation.approved', $reconciliation, ['status' => BankReconciliation::STATUS_OPEN], [
                'status' => BankReconciliation::STATUS_APPROVED,
                'difference_minor' => 0,
                'adjustment_document_ids' => $reconciliation->adjustment_document_ids ?? [],
            ], $actorId);

            return $reconciliation->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Read model
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function detail(BankReconciliation $reconciliation): array
    {
        return $this->compute($reconciliation);
    }

    /** @return array<string, mixed> */
    private function compute(BankReconciliation $reconciliation): array
    {
        $reconciliation->load('cashAccount.account', 'cashAccount.branch', 'branch', 'statement', 'approvedBy');
        $bankLines = $this->bankLines($reconciliation);
        $bookLines = $this->bookLines($reconciliation);
        $summary = $this->summary($reconciliation, $bankLines, $bookLines);

        return [
            ...$reconciliation->toApiArray(),
            'summary' => $summary,
            'bank_lines' => $bankLines,
            'book_lines' => $bookLines,
            'matches' => $this->matchRows($reconciliation),
            'candidates' => $reconciliation->isApproved() ? [] : $this->candidates($bankLines, $bookLines),
            'differences' => $this->differences($bankLines, $bookLines),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function bankLines(BankReconciliation $reconciliation): array
    {
        $lines = $this->statementLineQuery($reconciliation)
            ->orderBy('line_date')
            ->orderBy('id')
            ->get();
        $consumed = $this->consumedBank($lines->pluck('id')->all());

        return $lines->map(function (BankStatementLine $line) use ($consumed) {
            $matched = (int) ($consumed[$line->id] ?? 0);
            $amount = abs($line->amount_minor);

            return [
                'id' => $line->public_id,
                'line_date' => $line->line_date?->toDateString(),
                'value_date' => $line->value_date?->toDateString(),
                'amount_minor' => $line->amount_minor,
                'balance_after_minor' => $line->balance_after_minor,
                'bank_reference' => $line->bank_reference,
                'description' => $line->description,
                'party_name' => $line->party_name,
                'direction' => $line->amount_minor >= 0 ? 'in' : 'out',
                'matched_minor' => $matched,
                'remaining_minor' => $amount - $matched,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function bookLines(BankReconciliation $reconciliation): array
    {
        $lines = $this->bookLineQuery($reconciliation)->orderBy('journal_entry_id')->orderBy('id')->get();
        $consumed = $this->consumedJournal($lines->pluck('id')->all());

        return $lines->map(function (JournalLine $line) use ($consumed) {
            $matched = (int) ($consumed[$line->id] ?? 0);
            $amount = $line->base_debit_minor > 0 ? $line->base_debit_minor : $line->base_credit_minor;

            return [
                'id' => $line->public_id,
                'journal_entry_id' => $line->entry?->public_id,
                'entry_number' => $line->entry?->number,
                'entry_date' => $line->entry?->entry_date?->toDateString(),
                'description' => $line->description ?? $line->entry?->memo,
                'source_type' => $line->entry?->source_type,
                'branch_id' => $line->branch?->public_id,
                'debit_minor' => $line->base_debit_minor,
                'credit_minor' => $line->base_credit_minor,
                'amount_minor' => $amount,
                'direction' => $line->base_debit_minor > 0 ? 'in' : 'out',
                'matched_minor' => $matched,
                'remaining_minor' => $amount - $matched,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function matchRows(BankReconciliation $reconciliation): array
    {
        return $reconciliation->matches()
            ->with(['bankLine', 'journalLine.entry'])
            ->orderBy('match_group')
            ->orderBy('id')
            ->get()
            ->map(fn (BankReconciliationMatch $match) => [
                'id' => $match->public_id,
                'group' => $match->match_group,
                'bank_line_id' => $match->bankLine?->public_id,
                'bank_line_date' => $match->bankLine?->line_date?->toDateString(),
                'bank_line_description' => $match->bankLine?->description,
                'bank_line_amount_minor' => $match->bankLine?->amount_minor,
                'journal_line_id' => $match->journalLine?->public_id,
                'journal_entry_number' => $match->journalLine?->entry?->number,
                'journal_entry_date' => $match->journalLine?->entry?->entry_date?->toDateString(),
                'journal_line_description' => $match->journalLine?->description,
                'amount_minor' => $match->amount_minor,
                'kind' => $match->kind,
                'note' => $match->note,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function summary(BankReconciliation $reconciliation, array $bankLines, array $bookLines): array
    {
        $tolerance = $this->tolerance();

        $bookMovement = 0;
        $depositsInTransit = 0;
        $outstandingPayments = 0;
        $toleranceDifference = 0;
        $from = $reconciliation->period_from->toDateString();
        $to = $reconciliation->period_to->toDateString();

        foreach ($bookLines as $line) {
            // Opening book balance already contains earlier postings; the
            // movement of this session only counts lines inside its period.
            $entryDate = (string) ($line['entry_date'] ?? '');
            if ($entryDate >= $from && $entryDate <= $to) {
                $bookMovement += $line['debit_minor'] - $line['credit_minor'];
            }
            $residual = $line['remaining_minor'];

            if ($line['matched_minor'] > 0) {
                $toleranceDifference += abs($residual);
            } elseif ($residual > 0) {
                if ($line['debit_minor'] > 0) {
                    $depositsInTransit += $residual;
                } else {
                    $outstandingPayments += $residual;
                }
            }
        }

        $unrecordedBankDebits = 0;
        $unrecordedBankCredits = 0;
        foreach ($bankLines as $line) {
            $residual = $line['remaining_minor'];

            if ($line['matched_minor'] > 0) {
                $toleranceDifference += abs($residual);
            } elseif ($residual > 0) {
                if ($line['amount_minor'] >= 0) {
                    $unrecordedBankCredits += $residual;
                } else {
                    $unrecordedBankDebits += $residual;
                }
            }
        }

        $bankMovement = (int) array_sum(array_map(fn (array $line) => $line['amount_minor'], $bankLines));
        $bookClosing = $reconciliation->opening_book_balance_minor + $bookMovement;

        $adjustedBank = $reconciliation->closing_bank_balance_minor + $depositsInTransit - $outstandingPayments;
        $adjustedBook = $bookClosing + $unrecordedBankCredits - $unrecordedBankDebits;
        $difference = $adjustedBank - $adjustedBook;

        return [
            'opening_book_minor' => $reconciliation->opening_book_balance_minor,
            'book_movement_minor' => $bookMovement,
            'book_closing_minor' => $bookClosing,
            'opening_bank_minor' => $reconciliation->opening_bank_balance_minor,
            'bank_movement_minor' => $bankMovement,
            'bank_closing_minor' => $reconciliation->closing_bank_balance_minor,
            'statement_balanced' => $reconciliation->opening_bank_balance_minor + $bankMovement === $reconciliation->closing_bank_balance_minor,
            'matched_bank_minor' => (int) array_sum(array_column($bankLines, 'matched_minor')),
            'matched_book_minor' => (int) array_sum(array_column($bookLines, 'matched_minor')),
            'deposits_in_transit_minor' => $depositsInTransit,
            'outstanding_payments_minor' => $outstandingPayments,
            'unrecorded_bank_credits_minor' => $unrecordedBankCredits,
            'unrecorded_bank_debits_minor' => $unrecordedBankDebits,
            'tolerance_difference_minor' => $toleranceDifference,
            'adjusted_bank_minor' => $adjustedBank,
            'adjusted_book_minor' => $adjustedBook,
            'difference_minor' => $difference,
            'tolerance_minor' => $tolerance,
            'window_days' => $this->windowDays(),
            // Reconciled means the adjusted balances agree AND no bank line is
            // still unrecorded. Outstanding book items (deposits in transit,
            // unpresented checks) may legitimately remain open.
            'reconciled' => abs($difference) <= $tolerance
                && $unrecordedBankDebits === 0
                && $unrecordedBankCredits === 0,
            'bank_lines' => count($bankLines),
            'book_lines' => count($bookLines),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function differences(array $bankLines, array $bookLines): array
    {
        $rows = [];

        foreach ($bankLines as $line) {
            $residual = $line['remaining_minor'];
            if ($residual <= 0) {
                continue;
            }

            $category = $line['matched_minor'] > 0
                ? self::CATEGORY_TOLERANCE
                : ($line['amount_minor'] >= 0 ? self::CATEGORY_UNRECORDED_BANK_CREDIT : self::CATEGORY_UNRECORDED_BANK_DEBIT);

            $rows[] = [
                'side' => 'bank',
                'category' => $category,
                'line_id' => $line['id'],
                'date' => $line['line_date'],
                'description' => $line['description'],
                'amount_minor' => $line['amount_minor'] >= 0 ? $residual : -$residual,
                'action' => $category === self::CATEGORY_TOLERANCE ? 'explain' : 'post_adjustment',
            ];
        }

        foreach ($bookLines as $line) {
            $residual = $line['remaining_minor'];
            if ($residual <= 0) {
                continue;
            }

            $category = $line['matched_minor'] > 0
                ? self::CATEGORY_TOLERANCE
                : ($line['debit_minor'] > 0 ? self::CATEGORY_DEPOSIT_IN_TRANSIT : self::CATEGORY_OUTSTANDING_PAYMENT);

            $rows[] = [
                'side' => 'book',
                'category' => $category,
                'line_id' => $line['id'],
                'date' => $line['entry_date'],
                'description' => $line['description'],
                'amount_minor' => $line['debit_minor'] > 0 ? $residual : -$residual,
                'action' => 'carry_forward',
            ];
        }

        return $rows;
    }

    /**
     * Suggested one-to-one candidates. Ambiguous candidates (a line with more
     * than one possible partner) require human review and are never auto-matched.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $bankLines, array $bookLines): array
    {
        $tolerance = $this->tolerance();
        $window = $this->windowDays();

        $unmatchedBank = array_filter($bankLines, fn (array $line) => $line['remaining_minor'] > 0);
        $unmatchedBook = array_filter($bookLines, fn (array $line) => $line['remaining_minor'] > 0);

        $pairs = [];
        foreach ($unmatchedBank as $bank) {
            foreach ($unmatchedBook as $book) {
                if (($bank['amount_minor'] >= 0) !== ($book['debit_minor'] > 0)) {
                    continue;
                }

                $difference = abs($bank['remaining_minor'] - $book['remaining_minor']);
                if ($difference > $tolerance) {
                    continue;
                }

                $days = (int) abs(
                    Carbon::parse($bank['line_date'])->startOfDay()->diffInDays(
                        Carbon::parse($book['entry_date'])->startOfDay()
                    )
                );
                if ($days > $window) {
                    continue;
                }

                $pairs[] = [
                    'bank_line_id' => $bank['id'],
                    'journal_line_id' => $book['id'],
                    'line_date' => $bank['line_date'],
                    'entry_date' => $book['entry_date'],
                    'bank_amount_minor' => $bank['amount_minor'],
                    'book_amount_minor' => $book['amount_minor'],
                    'amount_minor' => min($bank['remaining_minor'], $book['remaining_minor']),
                    'difference_minor' => $difference,
                    'days_apart' => $days,
                    'reference_match' => $this->referenceMatches($bank['bank_reference'], $book),
                    'ambiguous' => false,
                ];
            }
        }

        $bankCount = array_count_values(array_column($pairs, 'bank_line_id'));
        $bookCount = array_count_values(array_column($pairs, 'journal_line_id'));

        foreach ($pairs as $index => $pair) {
            $pairs[$index]['ambiguous'] = $bankCount[$pair['bank_line_id']] > 1 || $bookCount[$pair['journal_line_id']] > 1;
        }

        usort($pairs, function (array $a, array $b) {
            return [$a['ambiguous'], ! $a['reference_match'], $a['days_apart'], $a['difference_minor']]
                <=> [$b['ambiguous'], ! $b['reference_match'], $b['days_apart'], $b['difference_minor']];
        });

        return $pairs;
    }

    private function referenceMatches(?string $bankReference, array $bookLine): bool
    {
        $reference = $this->normalizeReference($bankReference);
        if ($reference === '') {
            return false;
        }

        foreach ([$bookLine['entry_number'] ?? null, $bookLine['description'] ?? null] as $candidate) {
            $normalized = $this->normalizeReference($candidate);
            if ($normalized !== '' && (str_contains($normalized, $reference) || str_contains($reference, $normalized))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReference(?string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $value));
    }

    // ------------------------------------------------------------------
    // Queries & balances
    // ------------------------------------------------------------------

    private function cashAccount(string $publicId): CashAccount
    {
        $cashAccount = CashAccount::query()->with('account')->where('public_id', $publicId)->first();
        if (! $cashAccount) {
            throw new DomainException('The selected bank account does not exist.');
        }
        if ($cashAccount->kind !== 'bank') {
            throw new DomainException('Bank reconciliation needs a registered bank account, not a cash box.');
        }
        if (! $cashAccount->is_active) {
            throw new DomainException('The selected bank account is inactive.');
        }

        return $cashAccount;
    }

    private function assertBranchAccess(CashAccount $cashAccount, ?int $actorId): void
    {
        if ($actorId === null || $cashAccount->branch_id === null) {
            return;
        }

        $user = User::query()->find($actorId);
        $branch = Branch::query()->find($cashAccount->branch_id);
        if ($user && $branch) {
            $this->branchScope->assertBranch($user, $branch);
        }
    }

    private function assertNotApproved(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->isApproved()) {
            throw new DomainException('This reconciliation is approved and locked. Create a new reconciliation to correct it.');
        }
    }

    private function statementLineQuery(BankReconciliation $reconciliation): Builder
    {
        return BankStatementLine::query()
            ->where('cash_account_id', $reconciliation->cash_account_id)
            ->whereDate('line_date', '>=', $reconciliation->period_from->toDateString())
            ->whereDate('line_date', '<=', $reconciliation->period_to->toDateString());
    }

    private function bookLineQuery(BankReconciliation $reconciliation): Builder
    {
        $cashAccount = $reconciliation->cashAccount;

        // Postings up to the period end, not only inside it: an unpresented
        // check or deposit in transit from an earlier period still affects the
        // bank statement and must stay visible so it can be matched when it
        // clears, or shown as an outstanding item.
        return JournalLine::query()
            ->with(['entry', 'account', 'branch'])
            ->where('account_id', $cashAccount->account_id)
            ->whereHas('entry', function (Builder $query) use ($reconciliation) {
                $query->where('status', 'posted')
                    ->whereDate('entry_date', '<=', $reconciliation->period_to->toDateString());
            })
            ->when($cashAccount->branch_id !== null, fn (Builder $query) => $query->where('branch_id', $cashAccount->branch_id));
    }

    /** @param  array<int, int>  $ids */
    private function consumedBank(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return BankReconciliationMatch::query()
            ->whereIn('bank_statement_line_id', $ids)
            ->groupBy('bank_statement_line_id')
            ->selectRaw('bank_statement_line_id, SUM(amount_minor) as total')
            ->pluck('total', 'bank_statement_line_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** @param  array<int, int>  $ids */
    private function consumedJournal(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return BankReconciliationMatch::query()
            ->whereIn('journal_line_id', $ids)
            ->groupBy('journal_line_id')
            ->selectRaw('journal_line_id, SUM(amount_minor) as total')
            ->pluck('total', 'journal_line_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function bookOpeningBalance(CashAccount $cashAccount, string $before): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $cashAccount->account_id)
            ->whereDate('je.entry_date', '<', $before)
            ->when($cashAccount->branch_id !== null, fn ($query) => $query->where('jl.branch_id', $cashAccount->branch_id))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    private function tolerance(): int
    {
        return max(0, $this->settings->int('treasury', 'reconciliation_tolerance_minor', 0));
    }

    private function windowDays(): int
    {
        return max(0, $this->settings->int('treasury', 'reconciliation_date_window_days', 5));
    }

    // ------------------------------------------------------------------
    // File parsing
    // ------------------------------------------------------------------

    /** @return array<int, array<int, string>> */
    private function readRows(string $content, string $format, string $delimiter): array
    {
        return match ($format) {
            'csv', 'txt' => $this->readCsv($content, $delimiter),
            'xlsx' => $this->readXlsx($content),
            default => throw new DomainException('Unsupported statement format "'.$format.'". Upload a CSV or XLSX file.'),
        };
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $content, string $delimiter): array
    {
        $delimiter = $delimiter === '' ? ',' : $delimiter;
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new DomainException('The statement file could not be read.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $row = array_map(fn ($value) => trim((string) $value), $row);
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Minimal dependency-free XLSX reader: the first worksheet plus shared
     * strings. Sufficient for tabular bank exports (no formulas/styles).
     *
     * @return array<int, array<int, string>>
     */
    private function readXlsx(string $content): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('XLSX import requires the PHP zip extension. Upload a CSV file instead.');
        }

        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        file_put_contents($path, $content);

        $zip = new ZipArchive;
        try {
            if ($zip->open($path) !== true) {
                throw new DomainException('The XLSX file could not be opened.');
            }

            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                throw new DomainException('The XLSX file has no first worksheet.');
            }

            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $xml = simplexml_load_string($sharedXml);
                if ($xml !== false) {
                    foreach ($xml->si as $item) {
                        $text = '';
                        if (isset($item->t)) {
                            $text .= (string) $item->t;
                        }
                        foreach ($item->r as $run) {
                            $text .= (string) $run->t;
                        }
                        $shared[] = $text;
                    }
                }
            }

            $xml = simplexml_load_string($sheet);
            if ($xml === false) {
                throw new DomainException('The XLSX worksheet could not be parsed.');
            }

            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $reference = (string) $cell['r'];
                    preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches);
                    $column = $this->columnIndex($matches[1] ?? 'A');
                    $type = (string) $cell['t'];
                    $value = (string) $cell->v;
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string) ($cell->is->t ?? '');
                    }
                    $cells[$column] = trim($value);
                }

                if ($cells === []) {
                    continue;
                }

                $last = max(array_keys($cells));
                $line = [];
                for ($index = 0; $index <= $last; $index++) {
                    $line[$index] = $cells[$index] ?? '';
                }
                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Resolve a bank-specific column mapping against the file header. Values
     * may be header names (case-insensitive) or zero-based column indexes.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $header
     * @return array<string, mixed>
     */
    private function normalizeMapping(array $input, array $header): array
    {
        $allowed = ['date', 'value_date', 'reference', 'description', 'party', 'currency', 'amount', 'money_in', 'money_out', 'debit', 'credit', 'balance', 'date_format'];
        $mapping = [];
        foreach ($input as $key => $value) {
            if (in_array($key, $allowed, true) && $value !== null && $value !== '') {
                $mapping[$key] = $value;
            }
        }

        if (! isset($mapping['date'])) {
            $mapping['date'] = $this->findHeader($header, ['date', 'transaction date', 'value date', 'التاريخ'])
                ?? throw new DomainException('The statement needs a date column. Set the mapping for the date column.');
        }

        $hasAmount = isset($mapping['amount']) || isset($mapping['money_in']) || isset($mapping['money_out'])
            || isset($mapping['debit']) || isset($mapping['credit']);
        if (! $hasAmount) {
            $mapping['amount'] = $this->findHeader($header, ['amount', 'value', 'المبلغ'])
                ?? throw new DomainException('The statement needs an amount column. Map amount, money in/out, or debit/credit.');
        }

        $mapping['description'] ??= $this->findHeader($header, ['description', 'details', 'narrative', 'البيان']);
        $mapping['reference'] ??= $this->findHeader($header, ['reference', 'ref', 'reference number', 'المرجع']);
        $mapping['value_date'] ??= $this->findHeader($header, ['value date']);
        $mapping['balance'] ??= $this->findHeader($header, ['balance', 'running balance', 'الرصيد']);
        $mapping['party'] ??= $this->findHeader($header, ['party', 'beneficiary', 'اسم المستفيد']);

        return $mapping;
    }

    /** @param  array<int, string>  $header */
    private function findHeader(array $header, array $aliases): ?string
    {
        foreach ($header as $name) {
            $normalized = mb_strtolower(trim((string) $name));
            if (in_array($normalized, $aliases, true)) {
                return (string) $name;
            }
        }

        return null;
    }

    private function resolveColumn(mixed $reference, array $header): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }
        if (is_int($reference) || (is_string($reference) && ctype_digit($reference))) {
            return (int) $reference;
        }

        $needle = mb_strtolower(trim((string) $reference));
        foreach ($header as $index => $name) {
            if (mb_strtolower(trim((string) $name)) === $needle) {
                return $index;
            }
        }

        throw new DomainException('Column "'.$reference.'" was not found in the statement header.');
    }

    /**
     * Resolve every mapped column to a zero-based index against the header.
     *
     * @param  array<string, mixed>  $mapping
     * @param  array<int, string>  $header
     * @return array<string, int|null>
     */
    private function resolveColumns(array $mapping, array $header): array
    {
        $columns = [];
        foreach ($mapping as $key => $reference) {
            if ($key === 'date_format') {
                continue;
            }
            $columns[$key] = $this->resolveColumn($reference, $header);
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int|null>  $columns
     * @param  array<string, int>  $seenHashes
     * @return array<string, mixed>|null
     */
    private function parseStatementRow(array $row, array $columns, ?string $dateFormat, string $currency, CashAccount $cashAccount, array &$seenHashes): ?array
    {
        if (! isset($columns['date']) || $columns['date'] === null) {
            throw new DomainException('The date column mapping is invalid.');
        }

        $dateValue = trim((string) ($row[$columns['date']] ?? ''));
        if ($dateValue === '') {
            return null;
        }

        $amount = $this->amountFromRow($row, $columns, $currency);
        if ($amount === null) {
            return null;
        }

        $currencyValue = isset($columns['currency']) && $columns['currency'] !== null
            ? strtoupper(trim((string) ($row[$columns['currency']] ?? '')))
            : '';
        if ($currencyValue !== '' && $currencyValue !== $currency) {
            throw new DomainException(
                'Statement row currency '.$currencyValue.' does not match the bank account currency '.$currency.'.'
            );
        }

        $date = $this->parseDate($dateValue, $dateFormat);

        $valueDate = null;
        if (isset($columns['value_date']) && $columns['value_date'] !== null) {
            $valueDateValue = trim((string) ($row[$columns['value_date']] ?? ''));
            $valueDate = $valueDateValue === '' ? null : $this->parseDate($valueDateValue, $dateFormat);
        }

        $reference = $this->cell($row, $columns['reference'] ?? null);
        $description = $this->cell($row, $columns['description'] ?? null);
        $party = $this->cell($row, $columns['party'] ?? null);
        $balance = $this->cell($row, $columns['balance'] ?? null);
        $balanceMinor = $balance !== null && $balance !== '' ? $this->parseAmount($balance, $currency) : null;

        $base = implode('|', [$cashAccount->id, $date, $amount, $reference ?? '', $description ?? '']);
        $hash = hash('sha256', $base);
        if (isset($seenHashes[$hash])) {
            $seenHashes[$hash]++;
            $hash = hash('sha256', $base.'#'.$seenHashes[$hash]);
        } else {
            $seenHashes[$hash] = 1;
        }

        return [
            'line_hash' => $hash,
            'line_date' => $date,
            'value_date' => $valueDate,
            'amount_minor' => $amount,
            'balance_after_minor' => $balanceMinor,
            'bank_reference' => $reference,
            'description' => $description,
            'party_name' => $party,
            'raw' => array_combine(array_keys($columns), array_map(fn ($column) => $column === null ? null : ($row[$column] ?? null), $columns)) ?: null,
        ];
    }

    /** @param  array<int, string>  $row
     *  @param  array<string, int|null>  $columns */
    private function amountFromRow(array $row, array $columns, string $currency): ?int
    {
        $amount = $this->cell($row, $columns['amount'] ?? null);
        if ($amount !== null && $amount !== '') {
            return $this->parseAmount($amount, $currency);
        }

        $in = $this->cell($row, $columns['money_in'] ?? null);
        $out = $this->cell($row, $columns['money_out'] ?? null);
        if (($in !== null && $in !== '') || ($out !== null && $out !== '')) {
            return $this->parseAmount($in ?: '0', $currency) - $this->parseAmount($out ?: '0', $currency);
        }

        $debit = $this->cell($row, $columns['debit'] ?? null);
        $credit = $this->cell($row, $columns['credit'] ?? null);
        if (($debit !== null && $debit !== '') || ($credit !== null && $credit !== '')) {
            // On a customer statement a debit reduces the balance (money out),
            // a credit increases it (money in).
            return $this->parseAmount($credit ?: '0', $currency) - $this->parseAmount($debit ?: '0', $currency);
        }

        return null;
    }

    /** @param  array<int, string>  $row */
    private function cell(array $row, ?int $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $value = $row[$column] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    private function parseDate(string $value, ?string $format): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new DomainException('A statement row is missing its date.');
        }

        if ($format !== null && $format !== '') {
            if (! Carbon::hasFormat($value, $format)) {
                throw new DomainException('Date "'.$value.'" does not match the configured format "'.$format.'".');
            }

            return Carbon::createFromFormat($format, $value)->toDateString();
        }

        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays((int) $value)->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }

    private function parseAmount(string $value, string $currency): int
    {
        $clean = trim($value);
        $negative = false;
        if (str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
            $negative = true;
            $clean = substr($clean, 1, -1);
        }

        $clean = preg_replace('/[^0-9.,\-]/', '', $clean) ?? '';
        if ($clean === '' || $clean === '-') {
            return 0;
        }

        // Regional format with dot thousands and comma decimals ("1.234,56"):
        // convert it before Money, which otherwise strips the comma and would
        // read the amount as 123456. A plain thousands separator ("1,234.56")
        // never matches this pattern because its final group has 3 digits.
        if (str_contains($clean, ',') && preg_match('/^-?\d+(\.\d{3})*,\d{1,2}$/', $clean)) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        try {
            $minor = Money::fromDecimal($clean, $currency)->amountMinor;
        } catch (InvalidArgumentException) {
            throw new DomainException('Invalid amount "'.$value.'" in the statement.');
        }

        return $negative ? -abs($minor) : $minor;
    }
}
