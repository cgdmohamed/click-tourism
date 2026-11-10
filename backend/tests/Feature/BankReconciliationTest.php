<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\JournalEntry;
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

    private function importStatement(string $content, string $filename = 'statement.csv', array $extra = []): TestResponse
    {
        return $this->withHeaders($this->headers())->post('/api/v1/bank-statements/import', $extra + [
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
            'cash_account_id' => $this->bank()->public_id,
            'mapping' => $this->defaultMapping(),
        ]);
    }

    private function createSession(array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', $overrides + [
            'cash_account_id' => $this->bank()->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
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

    public function test_foreign_currency_bank_account_is_rejected(): void
    {
        $usd = Account::query()->create([
            'code' => '1130',
            'name_ar' => 'بنك دولاري',
            'name_en' => 'USD Bank',
            'type' => 'asset',
            'is_postable' => true,
            'is_active' => true,
            'currency_code' => 'USD',
        ]);
        $cash = CashAccount::query()->create([
            'account_id' => $usd->id,
            'kind' => 'bank',
            'name_ar' => 'بنك دولاري',
            'name_en' => 'USD Bank',
            'currency_code' => 'USD',
            'is_active' => true,
        ]);

        $this->withHeaders($this->headers())->postJson('/api/v1/reconciliations', [
            'cash_account_id' => $cash->public_id,
            'period_from' => $this->day(0),
            'period_to' => $this->day(9),
        ])->assertStatus(422);

        $this->assertSame(0, BankReconciliation::query()->count());
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
