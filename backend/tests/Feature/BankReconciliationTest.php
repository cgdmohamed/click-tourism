<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Models\BankReconciliationMatch;
use App\Modules\Treasury\Models\BankStatement;
use App\Modules\Treasury\Models\BankStatementLine;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Services\BankReconciliationService;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;
use ZipArchive;

class BankReconciliationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'reconciliation.view', 'reconciliation.manage', 'reconciliation.approve',
        'journal.view', 'journal.create', 'journal.reverse',
        'documents.view', 'documents.create', 'documents.approve',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        $this->seed(TreasurySeeder::class);

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function bank(): CashAccount
    {
        return CashAccount::query()
            ->whereHas('account', fn ($query) => $query->where('code', '1120'))
            ->firstOrFail();
    }

    /** A day inside the current fiscal period created by buildAccounting(). */
    private function day(int $offset): string
    {
        return now()->startOfMonth()->addDays($offset)->toDateString();
    }

    private function defaultMapping(): array
    {
        return [
            'date' => 'Date',
            'description' => 'Description',
            'reference' => 'Reference',
            'amount' => 'Amount',
            'date_format' => 'Y-m-d',
        ];
    }

    /**
     * Statement balances are mandatory (no silent defaults). Most tests only
     * care about the lines, so the helper supplies an opening balance of 0 and a
     * closing balance equal to the sum of a plain "Amount" column.
     */
    private function importStatement(string $content, string $filename = 'statement.csv', array $extra = []): TestResponse
    {
        $movement = 0;
        if (str_ends_with($filename, '.csv')) {
            $rows = array_map(fn ($row) => str_getcsv($row, str_contains($content, ';') ? ';' : ','), array_filter(preg_split('/\R/', $content)));
            $header = array_shift($rows) ?? [];
            $column = array_search('Amount', $header, true);
            if ($column !== false) {
                foreach ($rows as $row) {
                    $movement += (int) round((float) str_replace(',', '.', (string) ($row[$column] ?? '0')) * 100);
                }
            }
        }

        return $this->withHeaders($this->headers())->post('/api/v1/bank-statements/import', $extra + [
            'opening_balance_minor' => 0,
            'closing_balance_minor' => $movement,
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
            'cash_account_id' => $this->bank()->public_id,
            'mapping' => $this->defaultMapping(),
        ]);
    }

    private function createSession(array $overrides = []): TestResponse
    {
        // Bank balances are mandatory: default to an opening balance of 0 and a
        // closing balance equal to the imported movement of the period.
        $defaults = [
            'cash_account_id' => $this->bank()->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
        ];
        $period = $overrides + $defaults;

        return $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', $overrides + $defaults + [
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => (int) BankStatementLine::query()
                ->whereDate('line_date', '>=', $period['period_from'])
                ->whereDate('line_date', '<=', $period['period_to'])
                ->sum('amount_minor'),
        ]);
    }

    private function postJournal(string $date, int $amountMinor, bool $moneyIn, string $description): JournalEntry
    {
        $bank = $this->account('1120');
        $offset = $this->account($moneyIn ? '4100' : '6500');

        $lines = $moneyIn
            ? [
                PostingLine::debit($bank, $amountMinor, null, null, $description),
                PostingLine::credit($offset, $amountMinor, null, null, $description),
            ]
            : [
                PostingLine::debit($offset, $amountMinor, null, null, $description),
                PostingLine::credit($bank, $amountMinor, null, null, $description),
            ];

        return app(PostingService::class)->post(PostingRequest::make(
            entryDate: $date,
            branch: $this->branch,
            currency: 'EGP',
            lines: $lines,
            memo: $description,
            sourceType: 'test',
            actorId: $this->user->id,
        ));
    }

    /** @return array{id: string, lines: array<int, array{id: string, amount_minor: int}>} */
    private function sessionDetail(string $id): array
    {
        return $this->withHeaders($this->headers())
            ->getJson("/api/v1/reconciliations/{$id}")
            ->assertOk()
            ->json('data');
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    public function test_import_deduplicates_files_and_lines(): void
    {
        $csv = "Date,Description,Reference,Amount,Balance\n"
            .$this->day(3).",Deposit one,DEP-001,100.00,\"1,100.00\"\n"
            .$this->day(4).",Payment one,PAY-001,-40.00,\"1,060.00\"\n";

        $this->importStatement($csv)->assertCreated()
            ->assertJsonPath('data.imported_lines', 2)
            ->assertJsonPath('data.skipped_duplicates', 0)
            ->assertJsonPath('data.statement.line_count', 2)
            ->assertJsonPath('data.statement.currency', 'EGP');

        $this->assertSame(1, BankStatement::query()->count());
        $this->assertSame(2, BankStatementLine::query()->count());

        // Re-uploading the exact same file is rejected by the file hash.
        $this->importStatement($csv)->assertStatus(422);

        // An overlapping file imports only the new line and reports the rest.
        $overlapping = "Date,Description,Reference,Amount,Balance\n"
            .$this->day(3).",Deposit one,DEP-001,100.00,\"1,100.00\"\n"
            .$this->day(5).",Third line,REF-003,25.00,\"1,025.00\"\n";

        $this->importStatement($overlapping)->assertCreated()
            ->assertJsonPath('data.imported_lines', 1)
            ->assertJsonPath('data.skipped_duplicates', 1)
            ->assertJsonPath('data.statement.line_count', 1);

        $this->assertSame(2, BankStatement::query()->count());
        $this->assertSame(3, BankStatementLine::query()->count());
        $this->assertSame(1 + 2, (int) BankStatement::query()->sum('line_count'));
    }

    public function test_import_reads_xlsx_with_mapping(): void
    {
        $content = $this->xlsxContent([
            ['Date', 'Description', 'Reference', 'Amount'],
            [$this->day(3), 'Deposit one', 'DEP-001', '100.00'],
            [$this->day(4), 'Payment one', 'PAY-001', '-40.00'],
        ]);

        $this->importStatement($content, 'statement.xlsx')->assertCreated()
            ->assertJsonPath('data.imported_lines', 2)
            ->assertJsonPath('data.statement.format', 'xlsx');

        $this->assertSame(2, BankStatementLine::query()->count());
        $this->assertSame(10000, (int) BankStatementLine::query()->orderBy('id')->first()->amount_minor);
    }

    public function test_import_reads_regional_decimal_comma_amounts(): void
    {
        $csv = "Date;Description;Reference;Amount;Balance\n"
            .$this->day(3).";Deposit one;DEP-001;100,50;\"1.100,50\"\n"
            .$this->day(4).";Payment one;PAY-001;-40,25;\"1.060,25\"\n"
            .$this->day(5).";Large payment;PAY-002;\"-1.234,56\";\"-174,31\"\n";

        $this->importStatement($csv, 'statement.csv', ['delimiter' => ';'])
            ->assertCreated()
            ->assertJsonPath('data.imported_lines', 3);

        $lines = BankStatementLine::query()->orderBy('id')->get();
        $this->assertSame(10050, (int) $lines[0]->amount_minor);
        $this->assertSame(-4025, (int) $lines[1]->amount_minor);
        $this->assertSame(-123456, (int) $lines[2]->amount_minor);
        $this->assertSame(110050, (int) $lines[0]->balance_after_minor);
    }

    public function test_import_rejects_currency_mismatch(): void
    {
        $csv = "Date,Description,Reference,Amount,Currency\n"
            .$this->day(3).",Deposit one,DEP-001,100.00,USD\n";

        $this->importStatement($csv, 'statement.csv', [
            'mapping' => $this->defaultMapping() + ['currency' => 'Currency'],
        ])->assertStatus(422);

        $this->assertSame(0, BankStatement::query()->count());
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    public function test_session_uses_ledger_opening_balance(): void
    {
        $this->postJournal($this->day(1), 50000, true, 'Opening deposit');

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Deposit one,DEP-001,250.00\n")->assertCreated();

        $statement = BankStatement::query()->firstOrFail();

        $session = $this->createSession([
            'statement_id' => $statement->public_id,
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 50000,
            'closing_bank_balance_minor' => 75000,
        ])->assertCreated()
            ->assertJsonPath('data.opening_book_balance_minor', 50000)
            ->assertJsonPath('data.opening_bank_balance_minor', 50000)
            ->assertJsonPath('data.closing_bank_balance_minor', 75000)
            ->json('data.id');

        $this->assertSame('open', BankReconciliation::query()->where('public_id', $session)->firstOrFail()->status);
    }

    private function usdBank(): CashAccount
    {
        $existing = CashAccount::query()->where('name_en', 'USD Bank')->first();
        if ($existing) {
            return $existing;
        }

        $usd = Account::query()->create([
            'code' => '1130',
            'name_ar' => 'بنك دولاري',
            'name_en' => 'USD Bank',
            'type' => 'asset',
            'is_postable' => true,
            'is_active' => true,
            'currency_code' => 'USD',
        ]);

        return CashAccount::query()->create([
            'account_id' => $usd->id,
            'kind' => 'bank',
            'name_ar' => 'بنك دولاري',
            'name_en' => 'USD Bank',
            'currency_code' => 'USD',
            'is_active' => true,
        ]);
    }

    private function postUsd(string $date, int $usdMinor, bool $moneyIn, string $rate, string $description): JournalEntry
    {
        $bank = $this->usdBank()->account;
        $counter = $this->account($moneyIn ? '4100' : '6500');
        $base = (int) round($usdMinor * (float) $rate);

        $lines = $moneyIn
            ? [PostingLine::debit($bank, $usdMinor, null, null, $description)->in('USD', $rate), PostingLine::credit($counter, $base, null, null, $description)]
            : [PostingLine::debit($counter, $base, null, null, $description), PostingLine::credit($bank, $usdMinor, null, null, $description)->in('USD', $rate)];

        return app(PostingService::class)->post(PostingRequest::make(
            entryDate: $date,
            branch: $this->branch,
            currency: 'EGP',
            lines: $lines,
            memo: $description,
            sourceType: 'test',
            actorId: $this->user->id,
        ));
    }

    public function test_foreign_currency_bank_account_reconciles_in_its_own_currency(): void
    {
        $usd = $this->usdBank();

        // USD 100.00 at 50 and USD 40.00 at 55: base amounts differ from native.
        $this->postUsd($this->day(1), 10000, true, '50', 'USD receipt one');
        $this->postUsd($this->day(2), 4000, true, '55', 'USD receipt two');

        // Period-end revaluation: base-only lines with zero transaction amount.
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: $this->day(4),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::baseOnly($usd->account, 70000, 0, 'USD', '57', null, null, 'Unrealized FX revaluation'),
                PostingLine::baseOnly($this->account('7901'), 0, 70000, 'EGP', '1', null, null, 'Unrealized FX gain'),
            ],
            memo: 'Revaluation',
            sourceType: 'test_reval',
            actorId: $this->user->id,
        ));

        // A legacy base-currency line on the foreign account (pre-currency tagging).
        $legacy = JournalEntry::query()->firstOrFail();
        JournalLine::query()->create([
            'journal_entry_id' => $legacy->id,
            'account_id' => $usd->account_id,
            'branch_id' => $this->branch->id,
            'debit_minor' => 123456,
            'credit_minor' => 0,
            'base_debit_minor' => 123456,
            'base_credit_minor' => 0,
            'currency_code' => 'EGP',
            'description' => 'Legacy base line',
        ]);

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Wire one,W-1,100.00\n"
            .$this->day(3).",Wire two,W-2,40.00\n", 'usd.csv', ['cash_account_id' => $usd->public_id])->assertCreated();

        $session = $this->createSession([
            'cash_account_id' => $usd->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 14000,
        ])->assertCreated()->assertJsonPath('data.currency', 'USD')->json('data.id');

        $detail = $this->sessionDetail($session);
        $this->assertSame(14000, $detail['summary']['book_closing_minor'], 'native USD balance, not the base equivalent');
        $this->assertSame(2, $detail['summary']['book_lines'], 'revaluation and legacy base lines are not reconciled');
        $this->assertSame(2, $detail['summary']['excluded_non_native_lines']);
        $this->assertSame(14000, $detail['summary']['deposits_in_transit_minor'], 'only the two USD receipts are outstanding');

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()->assertJsonPath('data.created', 2);

        $detail = $this->sessionDetail($session);
        $this->assertTrue($detail['summary']['reconciled']);
        $this->assertSame(0, $detail['summary']['difference_minor']);
    }

    public function test_foreign_currency_session_opening_balance_is_native(): void
    {
        $usd = $this->usdBank();
        $this->postUsd($this->day(1), 10000, true, '50', 'USD receipt');

        $this->createSession([
            'cash_account_id' => $usd->public_id,
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 10000,
            'closing_bank_balance_minor' => 10000,
        ])->assertCreated()->assertJsonPath('data.opening_book_balance_minor', 10000);
    }

    // ------------------------------------------------------------------
    // Reversed (cancelled) entries
    // ------------------------------------------------------------------

    public function test_a_receipt_and_its_reversal_pair_out_of_the_outstanding_items(): void
    {
        $receipt = $this->postJournal($this->day(2), 25000, true, 'Receipt to be cancelled');
        app(PostingService::class)->reverse($receipt, 'Receipt cancelled', $this->user->id);

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Other deposit,DEP-9,10.00\n")->assertCreated();
        $this->postJournal($this->day(3), 1000, true, 'Other deposit');

        $session = $this->createSession([
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 1000,
        ])->assertCreated()->json('data.id');

        $detail = $this->sessionDetail($session);
        $this->assertSame(1000, $detail['summary']['book_closing_minor']);
        $this->assertSame(1000, $detail['summary']['deposits_in_transit_minor'], 'only the genuine deposit is outstanding');
        $this->assertSame(0, $detail['summary']['outstanding_payments_minor']);
        $this->assertCount(2, array_filter($detail['book_lines'], fn ($line) => $line['reversed']));
        $this->assertCount(1, array_filter($detail['differences'], fn ($row) => $row['side'] === 'book'));

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()->assertJsonPath('data.created', 1);
        $this->assertTrue($this->sessionDetail($session)['summary']['reconciled']);

        // A reversed line can never be matched by hand.
        $reversed = collect($detail['book_lines'])->firstWhere('reversed', true);
        $bank = $detail['bank_lines'][0];
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/matches", [
            'items' => [['bank_line_id' => $bank['id'], 'journal_line_id' => $reversed['id'], 'amount_minor' => 1000]],
        ])->assertStatus(422);
    }

    public function test_reversal_of_a_matched_receipt_stays_an_outstanding_payment(): void
    {
        $receipt = $this->postJournal($this->day(2), 25000, true, 'Receipt cleared by bank');
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Customer transfer,CT-1,250.00\n")->assertCreated();

        $session = $this->createSession([
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 25000,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertOk();

        app(PostingService::class)->reverse($receipt, 'Refunded after clearing', $this->user->id);

        $detail = $this->sessionDetail($session);
        $this->assertSame(25000, $detail['summary']['outstanding_payments_minor']);
        $this->assertCount(0, array_filter($detail['book_lines'], fn ($line) => $line['reversed']));
        $this->assertTrue($detail['summary']['reconciled']);
    }

    // ------------------------------------------------------------------
    // Approval snapshot, reopening, duplicate vouchers
    // ------------------------------------------------------------------

    public function test_approved_session_keeps_its_snapshot_when_later_sessions_match_its_items(): void
    {
        $this->postJournal($this->day(8), 10000, true, 'Deposit in transit');

        $first = $this->createSession([
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 0,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$first}/approve")->assertOk();

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(12).",Deposit cleared,DEP-1,100.00\n")->assertCreated();
        $second = $this->createSession([
            'period_from' => $this->day(10),
            'period_to' => $this->day(19),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 10000,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/auto-match")
            ->assertOk()->assertJsonPath('data.created', 1);

        $frozen = $this->sessionDetail($first);
        $this->assertSame('approved', $frozen['status']);
        $this->assertSame(10000, $frozen['summary']['deposits_in_transit_minor']);
        $this->assertTrue($frozen['summary']['reconciled']);
        $this->assertSame(0, $frozen['summary']['difference_minor']);
        $this->assertSame([], $frozen['candidates']);
    }

    public function test_approved_session_can_be_reopened_with_a_reason_newest_first(): void
    {
        $first = $this->createSession([
            'period_from' => $this->day(0), 'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$first}/approve")->assertOk();
        $second = $this->createSession([
            'period_from' => $this->day(10), 'period_to' => $this->day(19),
            'opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/approve")->assertOk();

        // A reason is mandatory, and an older session cannot be reopened under a newer one.
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/reopen", [])->assertStatus(422);
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$first}/reopen", ['reason' => 'Wrong match'])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/reopen", ['reason' => 'Wrong match'])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.approved_at', null);
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$first}/reopen", ['reason' => 'Wrong match'])->assertOk();

        $record = BankReconciliation::query()->where('public_id', $second)->firstOrFail();
        $this->assertNull($record->snapshot);
        $this->assertDatabaseHas('audit_events', ['action' => 'bank_reconciliation.reopened']);

        // An open session cannot be "reopened" again, and viewers cannot reopen.
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/reopen", ['reason' => 'Again'])->assertStatus(422);
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo(['reconciliation.view', 'reconciliation.manage']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$second}/reopen", ['reason' => 'Nope'])->assertStatus(403);
    }

    public function test_the_same_bank_line_cannot_get_two_adjustment_vouchers(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Bank fee,FEE-001,-50.00\n")->assertCreated();
        $session = $this->createSession([
            'period_from' => $this->day(2), 'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => -5000,
        ])->assertCreated()->json('data.id');

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher")->assertCreated();
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher")->assertStatus(422);
        $this->assertSame(1, AccountingDocument::query()->where('type', AccountingDocument::TYPE_ADJUSTMENT_VOUCHER)->count());

        // The offset account can never be the bank account itself.
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher", [
            'charges_account_code' => '1120',
        ])->assertStatus(422);
    }

    public function test_unposted_adjustment_voucher_blocks_approval(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Bank fee,FEE-001,-50.00\n")->assertCreated();
        $this->postJournal($this->day(3), 5000, false, 'Bank fee booked by hand');
        $session = $this->createSession([
            'period_from' => $this->day(2), 'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => -5000,
        ])->assertCreated()->json('data.id');

        // The bank fee is matched to a hand-posted entry, but a draft voucher was proposed first.
        $detail = $this->sessionDetail($session);
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher")->assertCreated();
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertOk();
        $this->assertTrue($this->sessionDetail($session)['summary']['reconciled']);
        $this->assertNotEmpty($detail['bank_lines']);

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/approve")->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Import hardening
    // ------------------------------------------------------------------

    public function test_import_rejects_ambiguous_slash_dates_without_a_format(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n03/04/2026,Deposit,D-1,10.00\n", 'amb.csv', [
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ])->assertStatus(422);
        $this->assertSame(0, BankStatement::query()->count());

        // Unambiguous day-first values are read as day/month/year.
        $this->importStatement("Date,Description,Reference,Amount\n23/04/2026,Deposit,D-1,10.00\n", 'ok.csv', [
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ])->assertCreated();
        $this->assertSame('2026-04-23', BankStatementLine::query()->firstOrFail()->line_date->toDateString());
    }

    public function test_import_reports_an_unreadable_date_with_its_row_instead_of_failing(): void
    {
        $response = $this->importStatement("Date,Description,Reference,Amount\n".$this->day(1).",ok,R-1,1.00\nnot-a-date,bad,R-2,1.00\n", 'bad.csv', [
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ])->assertStatus(422);
        $this->assertStringContainsString('row 3', strtolower((string) $response->json('message')));
    }

    public function test_import_reads_arabic_digits_semicolons_trailing_minus_and_spreadsheet_floats(): void
    {
        $this->importStatement("Date;Description;Reference;Amount\n"
            .$this->day(1).";Arabic digits;R-1;١٬٢٣٤٫٥٦\n"
            .$this->day(2).";Trailing minus;R-2;50.00-\n"
            .$this->day(3).";Float artefact;R-3;12.339999999999998\n"
            .$this->day(4).";Placeholder;R-4;-\n")->assertCreated()->assertJsonPath('data.imported_lines', 3);

        $amounts = BankStatementLine::query()->orderBy('line_date')->pluck('amount_minor')->all();
        $this->assertSame([123456, -5000, 1234], $amounts);
    }

    public function test_import_rejects_text_in_the_amount_column(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n".$this->day(1).",Odd,R-1,n/a\n")->assertStatus(422);
    }

    public function test_import_converts_windows_1256_and_truncates_long_text(): void
    {
        $arabic = (string) iconv('UTF-8', 'CP1256', 'إيداع نقدي');
        $csv = "Date,Description,Reference,Amount\n".$this->day(1).','.$arabic.",R-1,10.00\n"
            .$this->day(2).','.str_repeat('x', 400).",R-2,20.00\n";

        $this->importStatement($csv)->assertCreated();

        $lines = BankStatementLine::query()->orderBy('line_date')->get();
        $this->assertSame('إيداع نقدي', $lines[0]->description);
        $this->assertSame(255, mb_strlen((string) $lines[1]->description));
    }

    public function test_import_accepts_excel_serial_dates_even_with_a_date_format(): void
    {
        $serial = (string) intdiv(strtotime($this->day(2).' UTC') - strtotime('1899-12-30 UTC'), 86400);
        $content = $this->xlsxContent([['Date', 'Description', 'Reference', 'Amount'], [$serial, 'Serial date', 'R-1', '75.50']]);

        $this->importStatement($content, 'serial.xlsx')->assertCreated();
        $this->assertSame($this->day(2), BankStatementLine::query()->firstOrFail()->line_date->toDateString());
    }

    public function test_import_refuses_files_with_too_many_rows(): void
    {
        $rows = ['Date,Description,Reference,Amount'];
        for ($i = 0; $i <= BankReconciliationService::MAX_STATEMENT_ROWS; $i++) {
            $rows[] = $this->day(1).",r{$i},R-{$i},1.00";
        }

        $this->importStatement(implode("\n", $rows))->assertStatus(422);
        $this->assertSame(0, BankStatement::query()->count());
    }

    public function test_list_endpoint_omits_the_snapshot_payload(): void
    {
        $session = $this->createSession([
            'opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/approve")->assertOk();

        $this->assertNull($this->withHeaders($this->headers())->getJson('/api/v1/reconciliations')->json('data.0.snapshot'));
        $this->assertNotNull($this->sessionDetail($session)['snapshot']);
    }

    // ------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------

    public function test_auto_match_is_unambiguous_and_does_not_post(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Deposit one,DEP-001,100.00\n"
            .$this->day(4).",Payment one,PAY-001,-40.00\n")->assertCreated();

        $this->postJournal($this->day(3), 10000, true, 'Deposit one');
        $this->postJournal($this->day(4), 4000, false, 'Payment one');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $journalsBefore = JournalEntry::query()->count();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.ambiguous', 0)
            ->assertJsonPath('data.remaining', 0);

        // Matching never posts: the ledger is untouched.
        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame(2, BankReconciliationMatch::query()->count());

        // Replaying auto-match creates nothing new.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertSame(2, BankReconciliationMatch::query()->count());
    }

    public function test_ambiguous_matches_require_human_review(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Deposit one,DEP-001,100.00\n")->assertCreated();

        $this->postJournal($this->day(3), 10000, true, 'Candidate A');
        $this->postJournal($this->day(3), 10000, true, 'Candidate B');

        $session = $this->createSession()->assertCreated()->json('data.id');

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.ambiguous', 2);

        $this->assertSame(0, BankReconciliationMatch::query()->count());

        // A human chooses one of the two candidates explicitly.
        $detail = $this->sessionDetail($session);
        $bankLine = $detail['bank_lines'][0];
        $bookLine = collect($detail['book_lines'])->first(
            fn (array $line) => $line['description'] === 'Candidate A'
        );

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/matches", [
                'items' => [[
                    'bank_line_id' => $bankLine['id'],
                    'journal_line_id' => $bookLine['id'],
                    'amount_minor' => 10000,
                ]],
            ])->assertOk()
            ->assertJsonPath('data.summary.matched_bank_minor', 10000);

        $this->assertSame(1, BankReconciliationMatch::query()->count());
    }

    public function test_split_match_consumes_all_lines_and_blocks_double_match(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Grouped deposit,DEP-001,100.00\n")->assertCreated();

        $this->postJournal($this->day(3), 6000, true, 'Part A');
        $this->postJournal($this->day(3), 4000, true, 'Part B');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $detail = $this->sessionDetail($session);
        $bankLine = $detail['bank_lines'][0];
        $partA = collect($detail['book_lines'])->firstWhere('description', 'Part A');
        $partB = collect($detail['book_lines'])->firstWhere('description', 'Part B');

        // A partial match is rejected: every line must be consumed in full.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/matches", [
                'items' => [[
                    'bank_line_id' => $bankLine['id'],
                    'journal_line_id' => $partA['id'],
                    'amount_minor' => 5000,
                ]],
            ])->assertStatus(422);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/matches", [
                'items' => [
                    ['bank_line_id' => $bankLine['id'], 'journal_line_id' => $partA['id'], 'amount_minor' => 6000],
                    ['bank_line_id' => $bankLine['id'], 'journal_line_id' => $partB['id'], 'amount_minor' => 4000],
                ],
            ])->assertOk();

        $detail = $this->sessionDetail($session);
        $this->assertSame(0, $detail['bank_lines'][0]['remaining_minor']);
        $this->assertSame(0, collect($detail['book_lines'])->sum('remaining_minor'));

        // The fully consumed bank line cannot be matched again.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/matches", [
                'items' => [[
                    'bank_line_id' => $bankLine['id'],
                    'journal_line_id' => $partA['id'],
                    'amount_minor' => 6000,
                ]],
            ])->assertStatus(422);
    }

    public function test_unmatch_releases_lines(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Deposit one,DEP-001,100.00\n")->assertCreated();
        $this->postJournal($this->day(3), 10000, true, 'Deposit one');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertOk();

        $group = BankReconciliationMatch::query()->firstOrFail()->match_group;

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/reconciliations/{$session}/matches/{$group}")
            ->assertOk();

        $this->assertSame(0, BankReconciliationMatch::query()->count());
        $this->assertSame(10000, $this->sessionDetail($session)['bank_lines'][0]['remaining_minor']);
    }

    // ------------------------------------------------------------------
    // Differences, adjustment vouchers and approval
    // ------------------------------------------------------------------

    public function test_prior_period_outstanding_items_are_carried_into_the_session(): void
    {
        // A check written before the session period that has not cleared yet.
        $this->postJournal($this->day(1), 30000, false, 'Check 1001 to supplier');

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(6).",Deposit one,DEP-001,100.00\n")->assertCreated();

        $session = $this->createSession([
            'period_from' => $this->day(5),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 10000,
        ])->assertCreated()->json('data.id');

        $detail = $this->sessionDetail($session);
        $this->assertSame(-30000, $detail['summary']['opening_book_minor']);
        $this->assertSame(30000, $detail['summary']['outstanding_payments_minor']);
        $this->assertContains('outstanding_payment', array_column($detail['differences'], 'category'));
        $this->assertFalse($detail['summary']['reconciled'], 'The deposit is still unrecorded in the books.');

        // Recording the deposit and matching it leaves only the outstanding
        // check, which is carried forward rather than blocking approval.
        $this->postJournal($this->day(6), 10000, true, 'Deposit one');
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $detail = $this->sessionDetail($session);
        $this->assertSame(30000, $detail['summary']['outstanding_payments_minor']);
        $this->assertSame(0, $detail['summary']['difference_minor']);
        $this->assertTrue($detail['summary']['reconciled']);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(30000, $this->sessionDetail($session)['summary']['outstanding_payments_minor']);
    }

    public function test_unpresented_check_from_before_the_period_matches_when_it_clears(): void
    {
        $this->postJournal($this->day(1), 30000, false, 'Check 1001 to supplier');

        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(4).",Check 1001,CHK-1001,-300.00\n")->assertCreated();

        $session = $this->createSession([
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => -30000,
        ])->assertCreated()->json('data.id');

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $detail = $this->sessionDetail($session);
        $this->assertSame(0, $detail['summary']['outstanding_payments_minor']);
        $this->assertSame(0, $detail['summary']['difference_minor']);
        $this->assertTrue($detail['summary']['reconciled']);
    }

    public function test_missing_interest_account_gives_actionable_message(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Interest,INT-001,12.00\n")->assertCreated();
        $session = $this->createSession([
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => 1200,
        ])->assertCreated()->json('data.id');

        Account::query()->where('code', '7920')->delete();

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher");
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Bank interest account 7920 does not exist; run accounting:ensure-accounts --apply',
            $response->getContent(),
        );
    }

    public function test_adjustment_voucher_is_draft_until_posted_and_approval_locks_session(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Bank fee,FEE-001,-50.00\n")->assertCreated();

        $session = $this->createSession([
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => -5000,
        ])->assertCreated()->json('data.id');

        $detail = $this->sessionDetail($session);
        $this->assertFalse($detail['summary']['reconciled']);
        $this->assertSame(5000, $detail['summary']['unrecorded_bank_debits_minor']);
        $this->assertSame(0, $detail['summary']['difference_minor']);

        // The proposed voucher is a draft: nothing hits the ledger yet.
        $proposal = $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher", [
                'memo' => 'Bank fee adjustment',
            ])->assertCreated()
            ->assertJsonPath('data.document.type', 'adjustment_voucher')
            ->assertJsonPath('data.document.status', 'draft')
            ->json('data.document');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        // Approval is refused while the difference is unexplained.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/approve")
            ->assertStatus(422);

        // Post the voucher through the normal document workflow.
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$proposal['id']}/submit")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$proposal['id']}/approve")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$proposal['id']}/post")->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        // The posted fee is now a ledger line the bank fee can match.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $detail = $this->sessionDetail($session);
        $this->assertTrue($detail['summary']['reconciled']);
        $this->assertSame(0, $detail['summary']['difference_minor']);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $locked = BankReconciliation::query()->where('public_id', $session)->firstOrFail();
        $this->assertNotNull($locked->snapshot);
        $this->assertNotNull($locked->approved_at);
        $this->assertContains($proposal['id'], $locked->adjustment_document_ids);

        // Approved sessions are locked; corrections need a new session.
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertStatus(422);
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher")
            ->assertStatus(422);

        $group = BankReconciliationMatch::query()->firstOrFail()->match_group;
        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/reconciliations/{$session}/matches/{$group}")
            ->assertStatus(422);
    }

    public function test_adjustment_voucher_is_corrected_by_linked_reversal(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Bank fee,FEE-001,-50.00\n")->assertCreated();

        $session = $this->createSession([
            'period_from' => $this->day(2),
            'period_to' => $this->day(9),
            'opening_bank_balance_minor' => 0,
            'closing_bank_balance_minor' => -5000,
        ])->assertCreated()->json('data.id');

        $voucher = $this->withHeaders($this->headers())
            ->postJson("/api/v1/reconciliations/{$session}/adjustment-voucher")
            ->assertCreated()
            ->json('data.document');

        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$voucher['id']}/submit")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$voucher['id']}/approve")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$voucher['id']}/post")->assertOk();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        $correction = $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-documents/{$voucher['id']}/correct", [
                'reason' => 'Fee posted twice by mistake',
            ])->assertCreated()
            ->assertJsonPath('data.reversal_of_id', $voucher['id'])
            ->json('data.id');

        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$correction}/approve")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$correction}/post")->assertOk();

        $this->assertSame(2, JournalEntry::query()->where('source_type', 'accounting_document')->count());
        $this->assertSame(
            0,
            (int) BankReconciliationMatch::query()->count(),
            'Reconciliation matches are not silently rewritten by document corrections.'
        );
        $this->assertSame(
            'posted',
            AccountingDocument::query()->where('public_id', $voucher['id'])->firstOrFail()->status
        );
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function test_branch_scope_and_permissions_are_enforced(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Deposit one,DEP-001,100.00\n")->assertCreated();
        $session = $this->createSession()->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(self::PERMISSIONS);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);
        $this->withHeaders($this->headers($other))->getJson("/api/v1/reconciliations/{$session}")->assertStatus(403);
        $this->withHeaders($this->headers($other))->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertStatus(403);
        $this->assertCount(0, $this->withHeaders($this->headers($other))->getJson('/api/v1/reconciliations')->json('data'));

        // A read-only user cannot change matches.
        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo(['reconciliation.view']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertStatus(403);
        $this->withHeaders($this->headers())->getJson("/api/v1/reconciliations/{$session}")->assertOk();
    }

    // ------------------------------------------------------------------
    // XLSX fixture
    // ------------------------------------------------------------------

    /** @param  array<int, array<int, string>>  $rows */
    // ------------------------------------------------------------------
    // Open items: look-ahead, explicit balances, disambiguation, checklist
    // ------------------------------------------------------------------

    private function approve(string $session): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/approve");
    }

    public function test_ledger_line_posted_after_period_end_matches_but_never_enters_the_period_balance(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(9).",Late deposit,LATE-1,100.00\n")->assertCreated();
        $this->postJournal($this->day(11), 10000, true, 'Late deposit');
        // Not matched: a payment posted after period end is not an unpresented payment of the period.
        $this->postJournal($this->day(12), 4000, false, 'Later payment');

        $session = $this->createSession(['closing_bank_balance_minor' => 10000])->assertCreated()->json('data.id');
        $before = $this->sessionDetail($session);

        $late = collect($before['book_lines'])->firstWhere('description', 'Late deposit');
        $this->assertTrue($late['after_period_end']);
        $this->assertSame(0, $before['summary']['book_closing_minor'], 'ledger balance at period end is untouched');
        $this->assertSame(0, $before['summary']['deposits_in_transit_minor']);
        $this->assertSame(0, $before['summary']['outstanding_payments_minor']);
        $this->assertSame(31, $before['summary']['lookahead_days']);
        $this->assertFalse($before['summary']['reconciled'], 'the bank line is still unrecorded until matched');
        $this->assertTrue(collect($before['candidates'])->every(fn (array $pair) => $pair['after_period_end']));
        $this->assertCount(1, $before['candidates']);
        $this->assertNotContains('Later payment', array_column($before['differences'], 'description'));

        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()->assertJsonPath('data.created', 1);

        $after = $this->sessionDetail($session);
        $this->assertSame(0, $after['summary']['book_closing_minor']);
        $this->assertSame(10000, $after['summary']['recorded_after_period_minor']);
        $this->assertSame(1, $after['summary']['after_period_matched_lines']);
        $this->assertSame(0, $after['summary']['difference_minor']);
        $this->assertTrue($after['summary']['reconciled']);

        $this->approve($session)->assertOk()->assertJsonPath('data.summary.recorded_after_period_minor', 10000);

        // The next session sees the line as already cleared: no double use, no outstanding item.
        $next = $this->createSession([
            'period_from' => $this->day(10),
            'period_to' => $this->day(19),
            'opening_bank_balance_minor' => null,
            'closing_bank_balance_minor' => 10000,
        ])->assertCreated()
            ->assertJsonPath('data.balance_source.opening', 'previous_session')
            ->assertJsonPath('data.opening_bank_balance_minor', 10000)
            ->json('data.id');
        $nextDetail = $this->sessionDetail($next);
        $cleared = collect($nextDetail['book_lines'])->firstWhere('description', 'Late deposit');
        $this->assertSame(10000, $cleared['matched_minor']);
        $this->assertSame(0, $cleared['remaining_minor']);
        $this->assertSame(0, $nextDetail['summary']['deposits_in_transit_minor']);
        $this->assertSame(1, BankReconciliationMatch::query()->count());
        $this->assertSame(4000, $nextDetail['summary']['outstanding_payments_minor']);
        $this->assertSame(6000, $nextDetail['summary']['book_closing_minor']);
        $this->assertSame(0, $nextDetail['summary']['difference_minor']);

        // The approved snapshot never changed.
        $frozen = $this->sessionDetail($session);
        $this->assertSame(10000, $frozen['summary']['recorded_after_period_minor']);
        $this->assertSame(0, $frozen['summary']['book_closing_minor']);
    }

    public function test_look_ahead_window_is_a_validated_setting_and_zero_disables_it(): void
    {
        $this->postJournal($this->day(11), 10000, true, 'Late deposit');
        $session = $this->createSession(['closing_bank_balance_minor' => 0])->assertCreated()->json('data.id');
        $this->assertCount(1, $this->sessionDetail($session)['book_lines']);

        $this->user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'settings.update', 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['-1', 'abc', '400', ''] as $bad) {
            $this->withHeaders($this->headers())->putJson('/api/v1/settings', ['settings' => [
                ['group' => 'treasury', 'key' => 'reconciliation_lookahead_days', 'value' => $bad],
            ]])->assertStatus(422);
        }

        $this->withHeaders($this->headers())->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'treasury', 'key' => 'reconciliation_lookahead_days', 'value' => '0'],
        ]])->assertOk();
        app(SettingService::class)->set('treasury', 'reconciliation_lookahead_days', '0');

        $this->assertCount(0, $this->sessionDetail($session)['book_lines']);
    }

    public function test_a_look_ahead_reversal_does_not_hide_the_outstanding_receipt_of_the_period(): void
    {
        $receipt = $this->postJournal($this->day(8), 10000, true, 'Receipt to cancel');
        app(PostingService::class)->reverse($receipt, 'Cancelled', $this->user->id, $this->day(11));

        $session = $this->createSession(['closing_bank_balance_minor' => 0])->assertCreated()->json('data.id');
        $detail = $this->sessionDetail($session);

        $this->assertSame(10000, $detail['summary']['deposits_in_transit_minor'], 'at period end the receipt was a real deposit in transit');
        $this->assertTrue($detail['summary']['reconciled']);
    }

    public function test_equal_amount_candidates_are_disambiguated_by_reference_and_ties_stay_manual(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Transfer from customer,INV-777,100.00\n")->assertCreated();
        $this->postJournal($this->day(3), 10000, true, 'Receipt INV-555');
        $this->postJournal($this->day(3), 10000, true, 'Receipt INV-777');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.ambiguous', 0);

        $match = BankReconciliationMatch::query()->with('journalLine')->firstOrFail();
        $this->assertSame('Receipt INV-777', $match->journalLine->description);
    }

    public function test_equal_amount_candidates_with_the_same_evidence_stay_ambiguous(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Transfer,INV-777,100.00\n")->assertCreated();
        $this->postJournal($this->day(3), 10000, true, 'Receipt INV-777 first');
        $this->postJournal($this->day(3), 10000, true, 'Receipt INV-777 second');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.ambiguous', 2);
        $this->assertSame(0, BankReconciliationMatch::query()->count());
    }

    public function test_description_words_disambiguate_when_there_is_no_reference(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n"
            .$this->day(3).",Payment Nile Cruise deposit,,100.00\n")->assertCreated();
        $this->postJournal($this->day(3), 10000, true, 'Pyramids tour balance');
        $this->postJournal($this->day(3), 10000, true, 'Nile Cruise booking');

        $session = $this->createSession()->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")
            ->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame('Nile Cruise booking', BankReconciliationMatch::query()->with('journalLine')->firstOrFail()->journalLine->description);
    }

    public function test_statement_import_requires_explicit_balances(): void
    {
        $csv = "Date,Description,Reference,Amount\n".$this->day(3).",Deposit,D-1,100.00\n";
        $post = fn (array $extra) => $this->withHeaders($this->headers())->post('/api/v1/bank-statements/import', $extra + [
            'file' => UploadedFile::fake()->createWithContent('s'.count($extra).'.csv', $csv.str_repeat('', count($extra))),
            'cash_account_id' => $this->bank()->public_id,
            'mapping' => $this->defaultMapping(),
        ]);

        // Nothing to derive the balances from: refused instead of defaulting to 0.
        $post([])->assertStatus(422);
        $post(['opening_balance_minor' => 0])->assertStatus(422);
        $this->assertSame(0, BankStatement::query()->count());

        $this->importStatement($csv, 'explicit.csv', ['opening_balance_minor' => 5000, 'closing_balance_minor' => 15000])
            ->assertCreated()
            ->assertJsonPath('data.statement.balance_source.opening', 'explicit')
            ->assertJsonPath('data.statement.opening_balance_minor', 5000);
    }

    public function test_statement_balances_come_from_the_balance_column_or_the_previous_approved_session(): void
    {
        $withBalance = "Date,Description,Reference,Amount,Balance\n"
            .$this->day(3).",Deposit,D-1,100.00,\"1,100.00\"\n"
            .$this->day(4).",Payment,P-1,-40.00,\"1,060.00\"\n";
        $this->withHeaders($this->headers())->post('/api/v1/bank-statements/import', [
            'file' => UploadedFile::fake()->createWithContent('bal.csv', $withBalance),
            'cash_account_id' => $this->bank()->public_id,
            'mapping' => $this->defaultMapping() + ['balance' => 'Balance'],
        ])->assertCreated()
            ->assertJsonPath('data.statement.opening_balance_minor', 100000)
            ->assertJsonPath('data.statement.closing_balance_minor', 106000)
            ->assertJsonPath('data.statement.balance_source.opening', 'line_balance')
            ->assertJsonPath('data.statement.balance_source.closing', 'line_balance');

        // Approve a session so later statements can carry its closing balance forward.
        $this->postJournal($this->day(3), 10000, true, 'Deposit');
        $this->postJournal($this->day(4), 4000, false, 'Payment');
        $first = $this->createSession(['opening_bank_balance_minor' => 100000, 'closing_bank_balance_minor' => 106000])
            ->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$first}/auto-match")->assertOk();
        $this->assertTrue($this->sessionDetail($first)['summary']['statement_balanced'] === true);
    }

    public function test_opening_balance_is_carried_from_the_previous_approved_session(): void
    {
        $first = $this->createSession(['opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0])
            ->assertCreated()->json('data.id');
        $this->approve($first)->assertOk();

        $csv = "Date,Description,Reference,Amount\n".$this->day(12).",Deposit,D-1,100.00\n";
        $this->importStatement($csv, 'next.csv', ['opening_balance_minor' => null, 'closing_balance_minor' => 10000])
            ->assertCreated()
            ->assertJsonPath('data.statement.opening_balance_minor', 0)
            ->assertJsonPath('data.statement.balance_source.opening', 'previous_session')
            ->assertJsonPath('data.statement.balance_source.closing', 'explicit');
    }

    public function test_session_requires_bank_balances_and_shows_where_they_came_from(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', [
            'cash_account_id' => $this->bank()->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
        ])->assertStatus(422);

        $this->createSession(['opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0])
            ->assertCreated()
            ->assertJsonPath('data.balance_source.opening', 'explicit')
            ->assertJsonPath('data.balance_source.closing', 'explicit');

        $this->importStatement("Date,Description,Reference,Amount\n".$this->day(3).",Deposit,D-1,100.00\n", 'cover.csv', [
            'period_from' => $this->day(0), 'period_to' => $this->day(9),
            'opening_balance_minor' => 2000, 'closing_balance_minor' => 12000,
        ])->assertCreated();
        $statement = BankStatement::query()->firstOrFail();

        // A session over exactly the statement period uses the statement balances; a different period does not.
        $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', [
            'cash_account_id' => $this->bank()->public_id,
            'statement_id' => $statement->public_id,
        ])->assertCreated()
            ->assertJsonPath('data.opening_bank_balance_minor', 2000)
            ->assertJsonPath('data.closing_bank_balance_minor', 12000)
            ->assertJsonPath('data.balance_source.opening', 'statement');

        $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', [
            'cash_account_id' => $this->bank()->public_id,
            'statement_id' => $statement->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(5),
        ])->assertStatus(422);
    }

    public function test_approval_is_blocked_when_the_bank_balances_were_never_confirmed(): void
    {
        $this->importStatement("Date,Description,Reference,Amount\n".$this->day(3).",Deposit,D-1,100.00\n")->assertCreated();
        $this->postJournal($this->day(3), 10000, true, 'Deposit');
        $session = $this->createSession()->assertCreated()->json('data.id');
        $this->withHeaders($this->headers())->postJson("/api/v1/reconciliations/{$session}/auto-match")->assertOk();

        // A session created before balance sources were recorded whose opening balance disagrees with the statement.
        BankReconciliation::query()->where('public_id', $session)->update(['balance_source' => null, 'opening_bank_balance_minor' => 777]);

        $this->approve($session)->assertStatus(422);
        $this->assertSame('open', BankReconciliation::query()->where('public_id', $session)->value('status'));

        // Entering the balances explicitly (a new session) is the way forward.
        BankReconciliation::query()->where('public_id', $session)->update(['opening_bank_balance_minor' => 0]);
        $this->approve($session)->assertOk();
    }

    public function test_period_end_checklist_warns_about_bank_accounts_without_a_reconciliation(): void
    {
        $this->user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'periods.view', 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJournal($this->day(3), 10000, true, 'Deposit');
        $period = FiscalPeriod::query()->where('name', now()->format('Y-m'))->firstOrFail();

        $check = fn () => collect($this->withHeaders($this->headers())
            ->getJson("/api/v1/fiscal-periods/{$period->id}/checklist")->assertOk()->json('data.checks'))
            ->firstWhere('key', 'unreconciled_bank_accounts');

        $warning = $check();
        $this->assertFalse($warning['passed']);
        $this->assertFalse($warning['blocking'], 'a warning, never a closing blocker');
        $this->assertSame($this->bank()->public_id, $warning['details'][0]['id']);
        $this->assertTrue($this->withHeaders($this->headers())
            ->getJson("/api/v1/fiscal-periods/{$period->id}/checklist")->json('data.can_close'));

        $session = $this->createSession(['opening_bank_balance_minor' => 0, 'closing_bank_balance_minor' => 0])
            ->assertCreated()->json('data.id');
        $this->assertTrue($check()['passed'], 'an open session is enough to clear the warning');
    }

    private function xlsxContent(array $rows): string
    {
        $shared = [];
        $sharedIndex = function (string $value) use (&$shared): int {
            $index = array_search($value, $shared, true);
            if ($index === false) {
                $shared[] = $value;
                $index = count($shared) - 1;
            }

            return $index;
        };

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $sheetRows .= '<row r="'.($rowIndex + 1).'">';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnLetter($columnIndex).($rowIndex + 1);
                $sheetRows .= '<c r="'.$reference.'" t="s"><v>'.$sharedIndex((string) $value).'</v></c>';
            }
            $sheetRows .= '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';

        $sharedXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">';
        foreach ($shared as $value) {
            $sharedXml .= '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>';
        }
        $sharedXml .= '</sst>';

        $path = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
