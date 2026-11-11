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
use Illuminate\Support\Collection;
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

    /** Hard cap on data rows per statement file (the import runs in one transaction). */
    public const MAX_STATEMENT_ROWS = 20000;

    /** Cap on the uncompressed size of an XLSX worksheet / shared strings part. */
    private const MAX_XLSX_PART_BYTES = 40 * 1024 * 1024;

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

            if (count($rows) - 1 > self::MAX_STATEMENT_ROWS) {
                throw new DomainException('The statement has more than '.self::MAX_STATEMENT_ROWS.' rows. Split it into smaller files (for example one per month).');
            }

            $header = array_shift($rows);
            $mapping = $this->normalizeMapping($data['mapping'] ?? [], $header);
            $columns = $this->resolveColumns($mapping, $header);
            $dateFormat = isset($mapping['date_format']) ? (string) $mapping['date_format'] : null;

            $parsed = [];
            $seenHashes = [];
            foreach ($rows as $index => $row) {
                try {
                    $line = $this->parseStatementRow($row, $columns, $dateFormat, $currency, $cashAccount, $seenHashes);
                } catch (DomainException $exception) {
                    throw new DomainException('Statement row '.($index + 2).': '.$exception->getMessage());
                }
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

            // Balances are never guessed: they must be entered, read from the
            // file's running-balance column, or (opening only) carried over
            // from the previous approved reconciliation of the account.
            [$opening, $closing, $balanceSource] = $this->resolveImportBalances(
                $data,
                $parsed,
                $cashAccount,
                $from,
            );

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
                'balance_source' => $balanceSource,
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
                'balance_source' => $balanceSource,
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

            // Foreign-currency bank accounts reconcile in their own currency
            // (Odoo/Zoho behaviour): book amounts are the account's native
            // debit/credit, never the base-currency equivalents.
            $currency = strtoupper($cashAccount->currency_code);

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

            [$openingBank, $closingBank, $balanceSource] = $this->resolveSessionBalances(
                $data,
                $cashAccount,
                $statement,
                $from,
                $to,
            );

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
                'balance_source' => $balanceSource,
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
                'balance_source' => $balanceSource,
            ], $actorId);

            return $reconciliation->refresh()->load('cashAccount.account', 'branch');
        });
    }

    // ------------------------------------------------------------------
    // Statement balances (never defaulted silently)
    // ------------------------------------------------------------------

    /**
     * Opening/closing balance of an imported statement. Order of preference:
     * the value the user entered, the file's running-balance column, and for
     * the opening balance only, the closing balance of the previous approved
     * reconciliation (Odoo carries the last reconciled balance forward).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $parsed  all parsed rows in file order
     * @return array{0: int, 1: int, 2: array{opening: string, closing: string}}
     */
    private function resolveImportBalances(array $data, array $parsed, CashAccount $cashAccount, string $from): array
    {
        $explicitOpening = isset($data['opening_balance_minor']) && $data['opening_balance_minor'] !== null
            ? (int) $data['opening_balance_minor']
            : null;
        $explicitClosing = isset($data['closing_balance_minor']) && $data['closing_balance_minor'] !== null
            ? (int) $data['closing_balance_minor']
            : null;

        $lineOpening = null;
        $lineClosing = null;
        if ($parsed !== [] && ! in_array(null, array_column($parsed, 'balance_after_minor'), true)) {
            $ordered = $parsed;
            if ((string) $ordered[0]['line_date'] > (string) $ordered[count($ordered) - 1]['line_date']) {
                $ordered = array_reverse($ordered);
            }
            $first = $ordered[0];
            $last = $ordered[count($ordered) - 1];
            $lineOpening = (int) $first['balance_after_minor'] - (int) $first['amount_minor'];
            $lineClosing = (int) $last['balance_after_minor'];
        }

        $source = ['opening' => 'explicit', 'closing' => 'explicit'];
        $opening = $explicitOpening;
        if ($opening === null && $lineOpening !== null) {
            $opening = $lineOpening;
            $source['opening'] = 'line_balance';
        }
        if ($opening === null) {
            $previous = $this->previousApprovedClosing($cashAccount, $from);
            if ($previous !== null) {
                $opening = $previous;
                $source['opening'] = 'previous_session';
            }
        }
        if ($opening === null) {
            throw new DomainException('The statement opening balance is required: enter it, map a balance column, or approve an earlier reconciliation of this account to carry its closing balance forward.');
        }

        $closing = $explicitClosing;
        if ($closing === null && $lineClosing !== null) {
            $closing = $lineClosing;
            $source['closing'] = 'line_balance';
        }
        if ($closing === null) {
            throw new DomainException('The statement closing balance is required: enter it or map a balance column.');
        }

        return [$opening, $closing, $source];
    }

    /**
     * Bank-side balances of a session, with the same rule: explicit values,
     * the statement's balances when the session covers the whole statement,
     * the previous approved session's closing (opening) or the running balance
     * of the statement lines. Anything else is refused.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int, 2: array{opening: string, closing: string}}
     */
    private function resolveSessionBalances(array $data, CashAccount $cashAccount, ?BankStatement $statement, string $from, string $to): array
    {
        $explicitOpening = isset($data['opening_bank_balance_minor']) && $data['opening_bank_balance_minor'] !== null
            ? (int) $data['opening_bank_balance_minor']
            : null;
        $explicitClosing = isset($data['closing_bank_balance_minor']) && $data['closing_bank_balance_minor'] !== null
            ? (int) $data['closing_bank_balance_minor']
            : null;

        $samePeriod = $statement !== null
            && $statement->period_from?->toDateString() === $from
            && $statement->period_to?->toDateString() === $to;
        $statementSource = $statement !== null && $statement->balance_source === null ? 'statement_unverified' : 'statement';

        $source = ['opening' => 'explicit', 'closing' => 'explicit'];

        $opening = $explicitOpening;
        if ($opening === null && $samePeriod) {
            $opening = (int) $statement->opening_balance_minor;
            $source['opening'] = $statementSource;
        }
        if ($opening === null) {
            $previous = $this->previousApprovedClosing($cashAccount, $from);
            if ($previous !== null) {
                $opening = $previous;
                $source['opening'] = 'previous_session';
            }
        }
        if ($opening === null) {
            $balance = $this->lineBalance($cashAccount, $from, '<');
            if ($balance !== null) {
                $opening = $balance;
                $source['opening'] = 'line_balance';
            }
        }
        if ($opening === null) {
            throw new DomainException('The bank opening balance is required: enter it, pick a statement that covers this period, or approve an earlier reconciliation of this account.');
        }

        $closing = $explicitClosing;
        if ($closing === null && $samePeriod) {
            $closing = (int) $statement->closing_balance_minor;
            $source['closing'] = $statementSource;
        }
        if ($closing === null) {
            $balance = $this->lineBalance($cashAccount, $to, '<=', $from);
            if ($balance !== null) {
                $closing = $balance;
                $source['closing'] = 'line_balance';
            }
        }
        if ($closing === null) {
            throw new DomainException('The bank closing balance is required: enter it or pick a statement that covers this period.');
        }

        return [$opening, $closing, $source];
    }

    private function previousApprovedClosing(CashAccount $cashAccount, string $before): ?int
    {
        $previous = BankReconciliation::query()
            ->where('cash_account_id', $cashAccount->id)
            ->where('status', BankReconciliation::STATUS_APPROVED)
            ->whereDate('period_to', '<', $before)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();

        return $previous?->closing_bank_balance_minor;
    }

    /** Running balance of the latest statement line dated before / up to a day. */
    private function lineBalance(CashAccount $cashAccount, string $date, string $operator, ?string $notBefore = null): ?int
    {
        $line = BankStatementLine::query()
            ->where('cash_account_id', $cashAccount->id)
            ->whereNotNull('balance_after_minor')
            ->whereDate('line_date', $operator, $date)
            ->when($notBefore !== null, fn ($query) => $query->whereDate('line_date', '>=', $notBefore))
            ->orderByDesc('line_date')
            ->orderByDesc('id')
            ->first();

        return $line?->balance_after_minor;
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
            $this->lockAccount($reconciliation);
            $this->lockAccount($reconciliation);

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
            $this->lockAccount($reconciliation);
            $this->lockAccount($reconciliation);

            if ($items === []) {
                throw new DomainException('A match needs at least one bank line and one journal line.');
            }

            $bankLines = $this->statementLineQuery($reconciliation)->get()->keyBy('public_id');
            $journalLines = $this->bookLineQuery($reconciliation)->get()->keyBy('public_id');
            $bankConsumed = $this->consumedBank($bankLines->pluck('id')->all());
            $journalConsumed = $this->consumedJournal($journalLines->pluck('id')->all());
            $tolerance = $this->tolerance();
            $foreign = $this->isForeign($reconciliation->cashAccount);
            $voided = $this->voidedLineIds($journalLines->values(), $foreign, $journalConsumed, $reconciliation->period_to->toDateString());

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

                [$bookDebit, $bookCredit] = $this->nativeAmounts($book, $foreign);
                if (isset($voided[$book->id])) {
                    throw new DomainException("Item {$position}: this ledger line was reversed and is paired out; it cannot be matched.");
                }
                $bookIsDebit = $bookDebit > 0;
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
                $bookRemaining = ($bookIsDebit ? $bookDebit : $bookCredit) - ($journalConsumed[$book->id] ?? 0);
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
                    [$lineDebit, $lineCredit] = $this->nativeAmounts($line, $foreign);
                    $amount = ($lineDebit > 0 ? $lineDebit : $lineCredit) - ($journalConsumed[$line->id] ?? 0);
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
            $this->lockAccount($reconciliation);
            $this->lockAccount($reconciliation);

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
            $this->lockAccount($reconciliation);
            $this->lockAccount($reconciliation);

            if ($reconciliation->branch_id === null) {
                throw new DomainException('A branch is required to create the adjustment voucher.');
            }

            $detail = $this->compute($reconciliation);
            $selected = $data['bank_line_ids'] ?? null;
            $bankLines = collect($detail['bank_lines']);
            if (is_array($selected) && $selected !== []) {
                $bankLines = $bankLines->whereIn('id', $selected);
            }

            // A statement line that already has a live adjustment voucher
            // (draft, in approval or posted) is never proposed again: a second
            // voucher would book the same bank charge twice.
            $covered = $this->coveredBankLineIds($reconciliation);
            if (is_array($selected) && $selected !== [] && array_intersect($selected, $covered) !== []) {
                throw new DomainException('A selected bank line already has an adjustment voucher. Post or cancel that voucher first.');
            }

            $items = [];
            $dates = [];
            $itemLineIds = [];
            foreach ($bankLines as $line) {
                $residual = (int) $line['remaining_minor'];
                if ($residual <= 0 || in_array($line['id'], $covered, true)) {
                    continue;
                }
                $itemLineIds[] = $line['id'];
                if (! empty($line['line_date'])) {
                    $dates[] = (string) $line['line_date'];
                }
                $items[] = [
                    'direction' => $line['amount_minor'] >= 0 ? 'in' : 'out',
                    'amount_minor' => $residual,
                    'description' => mb_substr(trim(($line['description'] ?? 'Unrecorded bank item').' · '.($line['line_date'] ?? '')), 0, 250),
                ];
            }

            if ($items === []) {
                throw new DomainException('There are no unrecorded bank items to adjust.');
            }

            $cashAccount = $reconciliation->cashAccount;
            $bankAccount = $cashAccount->account->public_id;
            $charges = (string) ($data['charges_account_code'] ?? $this->settings->get('accounting', 'bank_charges_account_code', '6500'));
            $interest = (string) ($data['interest_account_code'] ?? $this->settings->get('accounting', 'bank_interest_account_code', '7920'));

            foreach ([$charges, $interest] as $offset) {
                if ($offset === $bankAccount || $offset === (string) $cashAccount->account->code) {
                    throw new DomainException('The charges/interest account cannot be the bank account itself.');
                }
            }

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
            $map = $reconciliation->adjustment_line_map ?? [];
            $map[$document->public_id] = $itemLineIds;
            $reconciliation->update([
                'adjustment_document_ids' => array_values(array_unique($ids)),
                'adjustment_line_map' => $map,
            ]);

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

    /**
     * Statement lines already covered by a live adjustment voucher of this
     * session: not rejected/cancelled/voided and not undone by a posted
     * correction (reversal) document.
     *
     * @return array<int, string>
     */
    private function coveredBankLineIds(BankReconciliation $reconciliation): array
    {
        $map = $reconciliation->adjustment_line_map ?? [];
        if ($map === []) {
            return [];
        }

        $documents = AccountingDocument::query()->whereIn('public_id', array_keys($map))->get();
        $undone = AccountingDocument::query()
            ->whereIn('reversal_of_id', $documents->pluck('id'))
            ->where('status', AccountingDocument::STATUS_POSTED)
            ->pluck('reversal_of_id')
            ->all();

        $covered = [];
        foreach ($documents as $document) {
            if (in_array($document->status, [AccountingDocument::STATUS_REJECTED, AccountingDocument::STATUS_CANCELLED, AccountingDocument::STATUS_VOIDED], true)
                || in_array($document->id, $undone, true)) {
                continue;
            }
            $covered = array_merge($covered, $map[$document->public_id] ?? []);
        }

        return array_values(array_unique($covered));
    }

    /**
     * Reopen an approved session (Odoo "reset to draft" / Zoho "undo
     * reconciliation"): only the newest approved session of an account can be
     * reopened, a reason is required, and the frozen snapshot is kept in the
     * audit trail. Nothing in the ledger changes.
     */
    public function reopen(BankReconciliation $reconciliation, string $reason, ?int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $reason, $actorId) {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->lockAccount($reconciliation);

            if (! $reconciliation->isApproved()) {
                throw new DomainException('Only an approved reconciliation can be reopened.');
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('A reason is required to reopen a reconciliation.');
            }

            $newer = BankReconciliation::query()
                ->where('cash_account_id', $reconciliation->cash_account_id)
                ->where('status', BankReconciliation::STATUS_APPROVED)
                ->where('id', '!=', $reconciliation->id)
                ->whereDate('period_to', '>', $reconciliation->period_to->toDateString())
                ->exists();
            if ($newer) {
                throw new DomainException('A later reconciliation of this bank account is approved. Reopen the latest one first.');
            }

            $before = [
                'status' => BankReconciliation::STATUS_APPROVED,
                'approved_by' => $reconciliation->approved_by,
                'approved_at' => $reconciliation->approved_at?->toIso8601String(),
                'summary' => $reconciliation->snapshot['summary'] ?? null,
            ];

            $reconciliation->update([
                'status' => BankReconciliation::STATUS_OPEN,
                'snapshot' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $this->audit->log('bank_reconciliation.reopened', $reconciliation, $before, [
                'status' => BankReconciliation::STATUS_OPEN,
                'reason' => $reason,
            ], $actorId);

            return $reconciliation->refresh();
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

            // A proposed adjustment voucher that is still a draft / awaiting
            // approval would be posted after the session is locked and create
            // an item the locked snapshot does not contain.
            $pending = AccountingDocument::query()
                ->whereIn('public_id', $reconciliation->adjustment_document_ids ?? [])
                ->whereIn('status', [AccountingDocument::STATUS_DRAFT, AccountingDocument::STATUS_SUBMITTED, AccountingDocument::STATUS_APPROVED])
                ->count();
            if ($pending > 0) {
                throw new DomainException('Post or cancel the proposed adjustment vouchers before approving the reconciliation.');
            }

            // The bank balances must be known, not defaulted: a session whose
            // balances were never confirmed (created before sources were
            // recorded, or taken from an unverified legacy statement) can only
            // be approved when its statement is internally consistent.
            $source = $reconciliation->balance_source;
            $unverified = ! is_array($source)
                || empty($source['opening']) || empty($source['closing'])
                || in_array('statement_unverified', [$source['opening'], $source['closing']], true);
            if ($unverified && ! $summary['statement_balanced']) {
                throw new DomainException('The bank opening/closing balances of this reconciliation were never confirmed and do not agree with the statement movement. Create a new reconciliation with explicit balances.');
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

        // An approved session is a frozen record: later matches in newer
        // sessions consume its outstanding items, which must not change what
        // was approved. Serve the stored snapshot, refreshed only with the
        // session header (status, approver, dates).
        if ($reconciliation->isApproved() && is_array($reconciliation->snapshot) && isset($reconciliation->snapshot['summary'])) {
            $frozen = $reconciliation->snapshot;
            unset($frozen['snapshot']);

            return [
                ...$frozen,
                ...$reconciliation->toApiArray(),
                'candidates' => [],
            ];
        }

        // The ledger may have received back-dated postings since the session
        // was opened; an open session always shows the live opening balance.
        if (! $reconciliation->isApproved()) {
            $liveOpening = $this->bookOpeningBalance($reconciliation->cashAccount, $reconciliation->period_from->toDateString());
            if ($liveOpening !== $reconciliation->opening_book_balance_minor) {
                $reconciliation->update(['opening_book_balance_minor' => $liveOpening]);
            }
        }

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
        $foreign = $this->isForeign($reconciliation->cashAccount);
        $lines = $this->bookLineQuery($reconciliation)->orderBy('journal_entry_id')->orderBy('id')->get();
        $consumed = $this->consumedJournal($lines->pluck('id')->all());
        $voided = $this->voidedLineIds($lines, $foreign, $consumed, $reconciliation->period_to->toDateString());
        $periodEnd = $reconciliation->period_to->toDateString();
        $matchedHere = $lines->isEmpty() ? [] : BankReconciliationMatch::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->groupBy('journal_line_id')
            ->selectRaw('journal_line_id, SUM(amount_minor) as total')
            ->pluck('total', 'journal_line_id')
            ->map(fn ($total) => (int) $total)
            ->all();

        return $lines->map(function (JournalLine $line) use ($consumed, $voided, $foreign, $periodEnd, $matchedHere) {
            [$debit, $credit] = $this->nativeAmounts($line, $foreign);
            $matched = (int) ($consumed[$line->id] ?? 0);
            $amount = $debit > 0 ? $debit : $credit;

            return [
                'id' => $line->public_id,
                'journal_entry_id' => $line->entry?->public_id,
                'entry_number' => $line->entry?->number,
                'entry_date' => $line->entry?->entry_date?->toDateString(),
                'entry_memo' => $line->entry?->memo,
                // Posted after the period end: loaded by the look-ahead window
                // for matching only, never part of this period's balances.
                'after_period_end' => (string) $line->entry?->entry_date?->toDateString() > $periodEnd,
                'matched_here_minor' => (int) ($matchedHere[$line->id] ?? 0),
                'description' => $line->description ?? $line->entry?->memo,
                'source_type' => $line->entry?->source_type,
                'branch_id' => $line->branch?->public_id,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'amount_minor' => $amount,
                'direction' => $debit > 0 ? 'in' : 'out',
                'matched_minor' => $matched,
                'remaining_minor' => $amount - $matched,
                // True when this line and its reversal cancel each other out
                // (cancelled receipt / supplier payment): they stay in the
                // book balance but are never outstanding items or candidates.
                'reversed' => isset($voided[$line->id]),
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
        $recordedAfterPeriod = 0;
        $afterPeriodMatched = 0;
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

            if (! empty($line['reversed'])) {
                continue;
            }

            if (! empty($line['after_period_end'])) {
                // Look-ahead item: not in the period's book balance, so an
                // unmatched one is neither a deposit in transit nor an
                // unpresented payment. One matched in THIS session is a bank
                // item the ledger only recorded after period end (cleared).
                if ($line['matched_here_minor'] > 0) {
                    $afterPeriodMatched++;
                    $recordedAfterPeriod += ($line['debit_minor'] > 0 ? 1 : -1) * $line['matched_here_minor'];
                }
                if ($line['matched_minor'] > 0) {
                    $toleranceDifference += abs($residual);
                }

                continue;
            }

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
        $adjustedBook = $bookClosing + $unrecordedBankCredits - $unrecordedBankDebits + $recordedAfterPeriod;
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
            'lookahead_days' => $this->lookaheadDays(),
            // Net ledger amount posted after period_to that clears bank lines of this
            // period. Added to the adjusted book side only; book_closing stays the
            // ledger balance at period end.
            'recorded_after_period_minor' => $recordedAfterPeriod,
            'after_period_matched_lines' => $afterPeriodMatched,
            // Reconciled means the adjusted balances agree AND no bank line is
            // still unrecorded. Outstanding book items (deposits in transit,
            // unpresented checks) may legitimately remain open.
            'reconciled' => abs($difference) <= $tolerance
                && $unrecordedBankDebits === 0
                && $unrecordedBankCredits === 0,
            'bank_lines' => count($bankLines),
            'book_lines' => count($bookLines),
            'reversed_pairs_minor' => (int) array_sum(array_map(
                fn (array $line) => ! empty($line['reversed']) && $line['debit_minor'] > 0 ? $line['amount_minor'] : 0,
                $bookLines,
            )),
            // Ledger lines of this account that carry no amount in its own
            // currency (base-only revaluation lines, legacy base-currency
            // lines on a foreign account) and are therefore not reconciled.
            'excluded_non_native_lines' => $this->excludedLineCount($reconciliation, count($bookLines)),
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
            if ($residual <= 0 || ! empty($line['reversed'])) {
                continue;
            }
            if (! empty($line['after_period_end']) && $line['matched_minor'] === 0) {
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

        // Only untouched lines are candidates: a line that is already matched
        // with a tolerance residual must not be paired a second time.
        $unmatchedBank = array_filter($bankLines, fn (array $line) => $line['remaining_minor'] > 0 && $line['matched_minor'] === 0);
        $unmatchedBook = array_filter(
            $bookLines,
            fn (array $line) => $line['remaining_minor'] > 0 && $line['matched_minor'] === 0 && empty($line['reversed']),
        );

        // Index ledger lines by direction and remaining amount so the search
        // is proportional to the number of lines, not their product.
        $index = [];
        foreach ($unmatchedBook as $book) {
            $index[($book['debit_minor'] > 0 ? 'in' : 'out').'|'.$book['remaining_minor']][] = $book;
        }
        $scanAll = $tolerance > 500;

        $pairs = [];
        foreach ($unmatchedBank as $bank) {
            $direction = $bank['amount_minor'] >= 0 ? 'in' : 'out';
            $found = [];
            if ($scanAll) {
                foreach ($unmatchedBook as $book) {
                    if (($book['debit_minor'] > 0 ? 'in' : 'out') === $direction && abs($bank['remaining_minor'] - $book['remaining_minor']) <= $tolerance) {
                        $found[] = $book;
                    }
                }
            } else {
                for ($delta = -$tolerance; $delta <= $tolerance; $delta++) {
                    foreach ($index[$direction.'|'.($bank['remaining_minor'] + $delta)] ?? [] as $book) {
                        $found[] = $book;
                    }
                }
            }

            foreach ($found as $book) {
                $difference = abs($bank['remaining_minor'] - $book['remaining_minor']);
                $days = $this->daysBetween((string) $bank['line_date'], (string) $book['entry_date']);
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
                    'evidence' => $this->evidenceScore($bank, $book),
                    'after_period_end' => ! empty($book['after_period_end']),
                    'disambiguated' => false,
                    'ambiguous' => false,
                ];
            }
        }

        $bankCount = array_count_values(array_column($pairs, 'bank_line_id'));
        $bookCount = array_count_values(array_column($pairs, 'journal_line_id'));

        foreach ($pairs as $position => $pair) {
            $pairs[$position]['ambiguous'] = $bankCount[$pair['bank_line_id']] > 1 || $bookCount[$pair['journal_line_id']] > 1;
        }

        $pairs = $this->disambiguate($pairs);

        // Fully ordered (ids last) so the outcome never depends on sort stability.
        usort($pairs, function (array $a, array $b) {
            return [$a['ambiguous'], ! $a['reference_match'], $a['days_apart'], $a['difference_minor'], $a['line_date'], $a['bank_line_id'], $a['journal_line_id']]
                <=> [$b['ambiguous'], ! $b['reference_match'], $b['days_apart'], $b['difference_minor'], $b['line_date'], $b['bank_line_id'], $b['journal_line_id']];
        });

        return $pairs;
    }

    /**
     * Among equal-amount candidates, prefer the pair whose bank reference /
     * description matches the entry number, memo or line description (Odoo
     * prefers the reference). A pair wins only when its evidence is strictly
     * the best for BOTH its bank line and its ledger line; the competing pairs
     * of those two lines are then dropped. Anything still tied stays
     * ambiguous and is left for manual matching.
     *
     * @param  array<int, array<string, mixed>>  $pairs
     * @return array<int, array<string, mixed>>
     */
    private function disambiguate(array $pairs): array
    {
        $byBank = [];
        $byBook = [];
        foreach ($pairs as $position => $pair) {
            $byBank[$pair['bank_line_id']][] = $position;
            $byBook[$pair['journal_line_id']][] = $position;
        }

        $top = function (array $positions) use ($pairs): array {
            $max = max(array_map(fn (int $position) => $pairs[$position]['evidence'], $positions));

            return [$max, count(array_filter($positions, fn (int $position) => $pairs[$position]['evidence'] === $max))];
        };

        $drop = [];
        foreach ($pairs as $position => $pair) {
            if (! $pair['ambiguous'] || $pair['evidence'] <= 0) {
                continue;
            }
            [$bankMax, $bankTies] = $top($byBank[$pair['bank_line_id']]);
            [$bookMax, $bookTies] = $top($byBook[$pair['journal_line_id']]);
            if ($pair['evidence'] !== $bankMax || $pair['evidence'] !== $bookMax || $bankTies !== 1 || $bookTies !== 1) {
                continue;
            }

            $pairs[$position]['ambiguous'] = false;
            $pairs[$position]['disambiguated'] = true;
            foreach (array_merge($byBank[$pair['bank_line_id']], $byBook[$pair['journal_line_id']]) as $other) {
                if ($other !== $position) {
                    $drop[$other] = true;
                }
            }
        }

        $kept = [];
        foreach ($pairs as $position => $pair) {
            if (! isset($drop[$position])) {
                $kept[] = $pair;
            }
        }

        return $kept;
    }

    /**
     * 4 bank reference matches the entry number, 3 it matches the memo or line
     * description, 2 the bank description contains the entry number, 1 they
     * share a word of 4+ characters, 0 no evidence.
     *
     * @param  array<string, mixed>  $bank
     * @param  array<string, mixed>  $book
     */
    private function evidenceScore(array $bank, array $book): int
    {
        $reference = $this->normalizeReference($bank['bank_reference'] ?? null);
        $number = $this->normalizeReference($book['entry_number'] ?? null);
        $texts = [
            $this->normalizeReference($book['entry_memo'] ?? null),
            $this->normalizeReference($book['description'] ?? null),
        ];
        $related = fn (string $a, string $b): bool => $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));

        if (strlen($reference) >= 3) {
            if ($related($number, $reference)) {
                return 4;
            }
            foreach ($texts as $text) {
                if ($related($text, $reference)) {
                    return 3;
                }
            }
        }

        $description = $this->normalizeReference(($bank['description'] ?? '').' '.($bank['party_name'] ?? ''));
        if (strlen($number) >= 4 && str_contains($description, $number)) {
            return 2;
        }

        $bankWords = $this->words(($bank['description'] ?? '').' '.($bank['bank_reference'] ?? '').' '.($bank['party_name'] ?? ''));
        $bookWords = $this->words(($book['entry_memo'] ?? '').' '.($book['description'] ?? '').' '.($book['entry_number'] ?? ''));
        if (array_intersect($bankWords, $bookWords) !== []) {
            return 1;
        }

        return 0;
    }

    /** @return array<int, string> distinct lowercase words of 4+ characters (Latin or Arabic) */
    private function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($parts, fn (string $word) => mb_strlen($word) >= 4)));
    }

    private function daysBetween(string $a, string $b): int
    {
        $first = strtotime($a.' 00:00:00 UTC');
        $second = strtotime($b.' 00:00:00 UTC');
        if ($first === false || $second === false) {
            return PHP_INT_MAX;
        }

        return (int) abs(intdiv($first - $second, 86400));
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

    private function bookLineBaseQuery(BankReconciliation $reconciliation): Builder
    {
        $cashAccount = $reconciliation->cashAccount;

        // Postings up to the period end, not only inside it: an unpresented
        // check or deposit in transit from an earlier period still affects the
        // bank statement and must stay visible so it can be matched when it
        // clears, or shown as an outstanding item. Postings up to the look-ahead
        // window AFTER the period end are loaded too, for matching only (Odoo
        // matches regardless of period); they never enter the period balance.
        return JournalLine::query()
            ->with(['entry', 'account', 'branch'])
            ->where('account_id', $cashAccount->account_id)
            ->whereHas('entry', function (Builder $query) use ($reconciliation) {
                $query->where('status', 'posted')
                    ->whereDate('entry_date', '<=', $this->bookWindowEnd($reconciliation));
            })
            ->when($cashAccount->branch_id !== null, fn (Builder $query) => $query->where('branch_id', $cashAccount->branch_id));
    }

    /** Last ledger date loaded for matching: period end plus the look-ahead window. */
    private function bookWindowEnd(BankReconciliation $reconciliation): string
    {
        return $reconciliation->period_to->copy()->addDays($this->lookaheadDays())->toDateString();
    }

    private function bookLineQuery(BankReconciliation $reconciliation): Builder
    {
        $query = $this->bookLineBaseQuery($reconciliation);
        $this->applyNativeScope($query, $reconciliation->cashAccount);

        return $query;
    }

    private function excludedLineCount(BankReconciliation $reconciliation, int $included): int
    {
        return max(0, $this->bookLineBaseQuery($reconciliation)->count() - $included);
    }

    private function isForeign(CashAccount $cashAccount): bool
    {
        return strtoupper((string) $cashAccount->currency_code) !== $this->settings->baseCurrency();
    }

    /**
     * Keep only ledger lines that carry an amount in the bank account's own
     * currency. Revaluation lines (zero transaction amount, base only) and
     * legacy base-currency lines on a foreign account have none, so they can
     * neither change the native book balance nor become phantom outstanding
     * items. Base-currency accounts use the base amounts as before.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function applyNativeScope($query, CashAccount $cashAccount, string $prefix = ''): void
    {
        if ($this->isForeign($cashAccount)) {
            $query->where($prefix.'currency_code', strtoupper((string) $cashAccount->currency_code))
                ->where(fn ($inner) => $inner->where($prefix.'debit_minor', '>', 0)->orWhere($prefix.'credit_minor', '>', 0));

            return;
        }

        $query->where(fn ($inner) => $inner->where($prefix.'base_debit_minor', '>', 0)->orWhere($prefix.'base_credit_minor', '>', 0));
    }

    /** @return array{0: int, 1: int} debit, credit in the bank account's own currency */
    private function nativeAmounts(JournalLine $line, bool $foreign): array
    {
        return $foreign
            ? [(int) $line->debit_minor, (int) $line->credit_minor]
            : [(int) $line->base_debit_minor, (int) $line->base_credit_minor];
    }

    /**
     * Ledger lines that cancel each other out: an entry on this account and
     * its reversal (cancelled receipt or supplier payment) with equal and
     * opposite amounts, neither matched to the statement. They are removed
     * from the outstanding items (net effect on the book balance is zero).
     * If the original was already matched to a bank line, its reversal stays
     * an outstanding payment on purpose.
     *
     * @param  Collection<int, JournalLine>  $lines
     * @param  array<int, int>  $consumed
     * @return array<int, true>
     */
    private function voidedLineIds($lines, bool $foreign, array $consumed, string $periodEnd): array
    {
        $byEntry = $lines->groupBy('journal_entry_id');
        $voided = [];

        foreach ($lines as $reversalLine) {
            $originalEntryId = $reversalLine->entry?->reversal_of_id;
            if ($originalEntryId === null || isset($voided[$reversalLine->id]) || ($consumed[$reversalLine->id] ?? 0) > 0) {
                continue;
            }
            // A reversal posted after the period end (look-ahead) does not
            // exist yet at period end: the original is a genuine outstanding
            // item of this period and is paired out in the later session.
            if ((string) $reversalLine->entry?->entry_date?->toDateString() > $periodEnd) {
                continue;
            }

            [$debit, $credit] = $this->nativeAmounts($reversalLine, $foreign);
            foreach ($byEntry->get($originalEntryId, collect()) as $original) {
                if (isset($voided[$original->id]) || ($consumed[$original->id] ?? 0) > 0) {
                    continue;
                }
                [$originalDebit, $originalCredit] = $this->nativeAmounts($original, $foreign);
                if ($originalDebit === $credit && $originalCredit === $debit) {
                    $voided[$original->id] = true;
                    $voided[$reversalLine->id] = true;
                    break;
                }
            }
        }

        return $voided;
    }

    private function lockAccount(BankReconciliation $reconciliation): void
    {
        // Serialises matching per bank account: line consumption is global
        // (across sessions), so two concurrent matches must not both pass.
        CashAccount::query()->whereKey($reconciliation->cash_account_id)->lockForUpdate()->first();
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
        $foreign = $this->isForeign($cashAccount);

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $cashAccount->account_id)
            ->whereDate('je.entry_date', '<', $before)
            ->when($cashAccount->branch_id !== null, fn ($query) => $query->where('jl.branch_id', $cashAccount->branch_id));
        $this->applyNativeScope($query, $cashAccount, 'jl.');

        $row = $query
            ->selectRaw($foreign
                ? 'COALESCE(SUM(jl.debit_minor), 0) as debit, COALESCE(SUM(jl.credit_minor), 0) as credit'
                : 'COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    private function tolerance(): int
    {
        return max(0, $this->settings->int('treasury', 'reconciliation_tolerance_minor', 0));
    }

    private function lookaheadDays(): int
    {
        return min(366, max(0, $this->settings->int('treasury', 'reconciliation_lookahead_days', 31)));
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
        $content = $this->toUtf8($content);
        $delimiter = $delimiter === '' ? ',' : $delimiter;

        $rows = $this->parseCsv($content, $delimiter);

        // Regional Excel exports use ";" (decimal comma) or tabs. When the
        // header collapsed into a single cell, retry with the delimiter the
        // first line actually contains.
        if (isset($rows[0]) && count($rows[0]) === 1) {
            $firstLine = (string) strtok($content, "\n");
            foreach ([';', "\t", '|'] as $candidate) {
                if ($candidate !== $delimiter && substr_count($firstLine, $candidate) > 0) {
                    $retry = $this->parseCsv($content, $candidate);
                    if (isset($retry[0]) && count($retry[0]) > 1) {
                        return $retry;
                    }
                }
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function parseCsv(string $content, string $delimiter): array
    {
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
            if (count(array_filter($row, fn ($value) => $value !== '')) === 0) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Bank exports are often UTF-16 (Excel "Unicode text") or Windows-1256
     * (Arabic). Invalid UTF-8 would otherwise abort the insert, so convert.
     */
    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding($content, 'UTF-8', 'UTF-16');
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $content) : false;

        return $converted !== false ? $converted : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
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

            foreach (['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'] as $part) {
                $stat = $zip->statName($part);
                if ($stat !== false && $stat['size'] > self::MAX_XLSX_PART_BYTES) {
                    throw new DomainException('The XLSX file is too large to import. Split it or export it as CSV.');
                }
            }

            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                throw new DomainException('The XLSX file has no first worksheet.');
            }

            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $xml = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);
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

            $xml = simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
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
        if ($amount === null || $amount === 0) {
            // Blank or zero rows (headers repeated per page, notes) carry no movement.
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
            // The columns are varchar(255): bank narratives can be longer.
            'bank_reference' => $reference === null ? null : mb_substr($reference, 0, 255),
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'party_name' => $party === null ? null : mb_substr($party, 0, 255),
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
        $value = $this->latinDigits(trim($value));
        if ($value === '') {
            throw new DomainException('A statement row is missing its date.');
        }

        // Excel serial dates (XLSX exports) are accepted whatever the format.
        $serial = preg_match('/^\d{5}(\.\d+)?$/', $value) === 1;

        if ($format !== null && $format !== '' && ! $serial) {
            if (! Carbon::hasFormat($value, $format)) {
                throw new DomainException('Date "'.$value.'" does not match the configured format "'.$format.'".');
            }

            return Carbon::createFromFormat($format, $value)->toDateString();
        }

        if ($serial) {
            return Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays((int) $value)->toDateString();
        }

        // Without a configured format only unambiguous dates are accepted:
        // "03/04/2026" is 3 April for most banks here and 4 March in the US,
        // and guessing silently moves lines into the wrong period.
        if (preg_match('/^(\d{1,2})([\/.\-])(\d{1,2})\2(\d{4})(?:\s.*)?$/', $value, $m) === 1) {
            $first = (int) $m[1];
            $second = (int) $m[3];
            if ($first > 12) {
                $day = $first;
                $month = $second;
            } elseif ($second > 12 || $first === $second) {
                $day = $second;
                $month = $first;
            } else {
                throw new DomainException('Date "'.$value.'" is ambiguous (day/month or month/day). Set the date format of the statement, for example d/m/Y.');
            }
            if (! checkdate($month, $day, (int) $m[4])) {
                throw new DomainException('Invalid date "'.$value.'" in the statement.');
            }

            return sprintf('%04d-%02d-%02d', (int) $m[4], $month, $day);
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new DomainException('Date "'.$value.'" could not be read. Set the date format of the statement.');
        }
    }

    /** Arabic-Indic and Persian digits and separators to ASCII. */
    private function latinDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',', '−' => '-', '–' => '-',
        ]);
    }

    private function parseAmount(string $value, string $currency): int
    {
        $clean = $this->latinDigits(trim($value));
        $negative = false;
        if (str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
            $negative = true;
            $clean = substr($clean, 1, -1);
        }
        // Trailing minus ("50.00-") and DR/CR suffixes used by some banks.
        if (preg_match('/^(.*?)\s*(-|dr|db)$/i', $clean, $m) === 1 && preg_match('/\d/', $m[1]) === 1) {
            $negative = true;
            $clean = $m[1];
        } elseif (preg_match('/^(.*?)\s*cr$/i', $clean, $m) === 1 && preg_match('/\d/', $m[1]) === 1) {
            $clean = $m[1];
        }

        if ($clean !== '' && preg_match('/\d/', $clean) !== 1 && preg_match('/^[\s\-.,]*$/', $clean) !== 1) {
            // Text such as "n/a" must not silently become a zero amount.
            throw new DomainException('Invalid amount "'.$value.'" in the statement.');
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

        // Spreadsheet floats ("12.339999999999998"): round half up at the
        // currency exponent instead of truncating, which would lose a cent.
        $clean = $this->roundToExponent($clean, $currency);

        try {
            $minor = Money::fromDecimal($clean, $currency)->amountMinor;
        } catch (InvalidArgumentException) {
            throw new DomainException('Invalid amount "'.$value.'" in the statement.');
        }

        return $negative ? -abs($minor) : $minor;
    }

    /** Round a plain decimal string half-up to the currency exponent (no floats). */
    private function roundToExponent(string $amount, string $currency): string
    {
        $plain = str_replace(',', '', $amount);
        if (preg_match('/^(-?)(\d+)\.(\d+)$/', $plain, $m) !== 1) {
            return $amount;
        }

        $scale = Money::exponent($currency);
        if (strlen($m[3]) <= $scale) {
            return $amount;
        }

        $kept = substr($m[3], 0, $scale);
        $roundUp = (int) $m[3][$scale] >= 5;
        $digits = $m[2].$kept;
        if ($roundUp) {
            $digits = $this->incrementDigits($digits);
        }
        $whole = substr($digits, 0, strlen($digits) - $scale);
        $fraction = $scale > 0 ? substr($digits, -$scale) : '';

        return $m[1].($whole === '' ? '0' : $whole).($scale > 0 ? '.'.$fraction : '');
    }

    private function incrementDigits(string $digits): string
    {
        $chars = str_split($digits);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] === '9') {
                $chars[$i] = '0';

                continue;
            }
            $chars[$i] = (string) ((int) $chars[$i] + 1);

            return implode('', $chars);
        }

        return '1'.implode('', $chars);
    }
}
