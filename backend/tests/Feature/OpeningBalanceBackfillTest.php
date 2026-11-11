<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Accounting\Services\OpeningBalanceService;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Reports\Services\PartyStatementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** accounting:backfill-opening-documents brings batches posted before party subaccounts up to date. */
class OpeningBalanceBackfillTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Customer $c1;

    private Customer $c2;

    private Supplier $s1;

    private function net(Account|string $account, ?int $branchId = null): int
    {
        $account = is_string($account) ? $this->account($account) : $account;
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->where('jl.account_id', $account->id)
            ->when($branchId, fn ($q) => $q->where('jl.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')->first();

        return (int) $row->d - (int) $row->c;
    }

    private function familyNet(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')->first();

        return (int) $row->d - (int) $row->c;
    }

    private function subaccount(string $type, int $id, string $role): Account
    {
        return PartyAccount::query()->where('party_type', $type)->where('party_id', $id)->where('role', $role)->firstOrFail()->account;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->c1 = $this->customer(); // creates the shared test customer
        $this->c2 = $this->customer($this->branch);
        $this->s1 = $this->supplier();
    }

    /**
     * A batch posted the way the service did before party subaccounts: party
     * lines on the control account, tagged with the party, and no documents.
     */
    private function legacyBatch(?Branch $branch = null, ?string $asOf = null, bool $withForeign = true): OpeningBalanceBatch
    {
        $branch ??= $this->branch;
        $service = app(OpeningBalanceService::class);
        $lines = [
            ['account_id' => '1100', 'debit_minor' => 5000000, 'party_type' => 'customer', 'party_id' => $this->c1->public_id],
            ['account_id' => '1100', 'credit_minor' => 400000, 'party_type' => 'customer', 'party_id' => $this->c1->public_id],
            ['account_id' => '2100', 'credit_minor' => 3000000, 'party_type' => 'supplier', 'party_id' => $this->s1->public_id],
            ['account_id' => '2100', 'debit_minor' => 250000, 'party_type' => 'supplier', 'party_id' => $this->s1->public_id],
            ['account_id' => '3200', 'credit_minor' => 1850000],
        ];
        if ($withForeign) {
            $lines[] = ['account_id' => '1100', 'debit_minor' => 10000, 'currency' => 'USD', 'fx_rate' => '50', 'party_type' => 'customer', 'party_id' => $this->c2->public_id];
            $lines[] = ['account_id' => '3200', 'credit_minor' => 10000, 'currency' => 'USD', 'fx_rate' => '50'];
        }
        $batch = $service->create([
            'as_of_date' => $asOf ?? now()->startOfMonth()->toDateString(),
            'lines' => $lines,
        ], $branch, $this->user->id);

        $batch->load('lines.account');
        foreach ($batch->lines->groupBy('currency_code') as $currency => $group) {
            $entry = app(PostingService::class)->post(PostingRequest::make(
                entryDate: $batch->as_of_date->toDateString(),
                branch: $branch,
                currency: $currency,
                lines: $group->map(fn ($line) => new PostingLine(
                    account: $line->account,
                    debitMinor: $line->debit_minor,
                    creditMinor: $line->credit_minor,
                    partyType: $line->party_type,
                    partyId: $line->party_id,
                    description: 'Opening balance',
                ))->all(),
                memo: 'Opening balances (legacy)',
                sourceType: 'opening_balance',
                sourceId: $batch->id,
                event: 'currency:'.$currency,
                fxRate: (string) $group->first()->fx_rate,
                fxRateDate: $batch->as_of_date->toDateString(),
                actorId: $this->user->id,
            ));
            $batch->entries()->attach($entry->id, ['currency_code' => $currency]);
        }
        $batch->update(['status' => 'posted', 'posted_by' => $this->user->id, 'posted_at' => now()]);

        return $batch->refresh();
    }

    private function counts(): array
    {
        return [
            JournalEntry::query()->count(), Invoice::query()->count(), Payment::query()->count(),
            SupplierPayment::query()->count(), AuditEvent::query()->count(),
            DB::table('journal_lines')->count(), Account::query()->count(), DB::table('party_accounts')->count(),
        ];
    }

    private function statementDifference(string $type, int $id): int
    {
        return app(PartyStatementService::class)
            ->statement($type, $id, now()->startOfYear()->toDateString(), now()->toDateString(), null)['reconciliation']['difference_minor'];
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->legacyBatch();
        $before = $this->counts();

        $this->artisan('accounting:backfill-opening-documents')->assertSuccessful();
        $this->artisan('accounting:backfill-opening-documents', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, Invoice::query()->where('is_opening', true)->count());
    }

    public function test_apply_moves_balances_creates_records_and_keeps_totals(): void
    {
        $this->legacyBatch();
        $arBefore = $this->familyNet('1100') + $this->net('2300');
        $apBefore = $this->familyNet('2100') + $this->net('1153');
        $this->assertNotSame(0, $this->statementDifference('customer', $this->c1->id), 'legacy state does not reconcile with documents');

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();

        // Control account emptied; party subaccounts and advance accounts hold the balances.
        $this->assertSame(0, $this->net('1100'));
        $this->assertSame(0, $this->net('2100'));
        $this->assertSame(5000000, $this->net($this->subaccount('customer', $this->c1->id, 'receivable')));
        $this->assertSame(500000, $this->net($this->subaccount('customer', $this->c2->id, 'receivable')), 'USD 100.00 at 50 keeps its base amount');
        $this->assertSame(-3000000, $this->net($this->subaccount('supplier', $this->s1->id, 'payable')));
        $this->assertSame(-400000, $this->net('2300'));
        $this->assertSame(250000, $this->net('1153'));

        // The combined control + advance totals are unchanged.
        $this->assertSame($arBefore, $this->familyNet('1100') + $this->net('2300'));
        $this->assertSame($apBefore, $this->familyNet('2100') + $this->net('1153'));

        // Party tags and currency survive on the new lines.
        $usd = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.source_type', 'opening_balance_backfill')->where('jl.currency_code', 'USD')
            ->where('jl.account_id', $this->subaccount('customer', $this->c2->id, 'receivable')->id)->first();
        $this->assertSame('customer', $usd->party_type);
        $this->assertSame($this->c2->id, (int) $usd->party_id);
        $this->assertSame(10000, (int) $usd->debit_minor);
        $this->assertSame(500000, (int) $usd->base_debit_minor);
        $this->assertSame(0, DB::table('journal_lines')->where('account_id', $this->account('6999')->id)->count(), 'no rounding residue');

        // One balanced, traceable entry per batch and currency, dated as the batch.
        $entries = JournalEntry::query()->where('source_type', 'opening_balance_backfill')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $batch = OpeningBalanceBatch::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['backfill:'.$batch->id.':EGP', 'backfill:'.$batch->id.':USD'],
            $entries->pluck('event')->all(),
        );
        foreach ($entries as $entry) {
            $this->assertSame($batch->id, (int) $entry->source_id);
            $this->assertSame($batch->as_of_date->toDateString(), $entry->entry_date->toDateString());
            $this->assertSame((int) $entry->lines->sum('base_debit_minor'), (int) $entry->lines->sum('base_credit_minor'));
        }

        // Documents and credit records are linked to their batch line.
        $this->assertSame(3, Invoice::query()->where('is_opening', true)->where('series', 'OB')->where('status', 'issued')->count());
        $doc = Invoice::query()->where('is_opening', true)->where('party_type', 'customer')->where('party_id', $this->c1->id)->firstOrFail();
        $this->assertSame(5000000, (int) $doc->total_minor);
        $this->assertSame(5000000, (int) $doc->outstanding_minor);
        $this->assertNotNull($doc->opening_balance_line_id);
        $this->assertSame($entries->firstWhere('currency_code', 'EGP')->id, $doc->journal_entry_id);
        $receipt = Payment::query()->where('is_opening', true)->firstOrFail();
        $this->assertSame(400000, (int) $receipt->unallocated_minor);
        $this->assertNull($receipt->cash_account_id);
        $this->assertSame('posted', $receipt->status);
        $this->assertSame(1, SupplierPayment::query()->where('is_opening', true)->count());

        // Audited as the system, and statements reconcile with the documents.
        $audit = AuditEvent::query()->where('action', 'opening_balance.backfilled')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame(0, $this->statementDifference('customer', $this->c1->id));
        $this->assertSame(0, $this->statementDifference('customer', $this->c2->id));
        $this->assertSame(0, $this->statementDifference('supplier', $this->s1->id));
    }

    public function test_backfilled_records_are_settleable_and_rerun_is_a_no_op(): void
    {
        $this->legacyBatch();
        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();
        $settlement = app(InvoiceSettlementService::class);

        $doc = Invoice::query()->where('is_opening', true)->where('party_type', 'customer')->where('party_id', $this->c1->id)->firstOrFail();
        $receipt = Payment::query()->where('is_opening', true)->firstOrFail();
        $settlement->applyReceipt($receipt, $doc, 400000, $this->user->id);
        $this->assertSame(4600000, (int) $doc->refresh()->outstanding_minor);
        $this->assertSame(0, $this->net('2300'), 'the opening credit has been applied');
        $this->assertSame(4600000, $this->net($this->subaccount('customer', $this->c1->id, 'receivable')));

        $supplierDoc = Invoice::query()->where('is_opening', true)->where('party_type', 'supplier')->where('party_id', $this->s1->id)->firstOrFail();
        $advance = SupplierPayment::query()->where('is_opening', true)->firstOrFail();
        $settlement->applySupplierPayment($advance, $supplierDoc, 250000, $this->user->id);
        $this->assertSame(2750000, (int) $supplierDoc->refresh()->outstanding_minor);
        $this->assertSame(0, $this->net('1153'));
        $this->assertSame(-2750000, $this->net($this->subaccount('supplier', $this->s1->id, 'payable')));
        $this->assertSame(0, $this->statementDifference('customer', $this->c1->id));
        $this->assertSame(0, $this->statementDifference('supplier', $this->s1->id));

        $before = $this->counts();
        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $this->counts());
    }

    public function test_reversal_of_a_backfilled_batch_is_refused_while_applied_then_clean(): void
    {
        $batch = $this->legacyBatch();
        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();
        $settlement = app(InvoiceSettlementService::class);
        $doc = Invoice::query()->where('is_opening', true)->where('party_type', 'customer')->where('party_id', $this->c1->id)->firstOrFail();
        $application = $settlement->applyReceipt(Payment::query()->where('is_opening', true)->firstOrFail(), $doc, 100000, $this->user->id);

        try {
            app(OpeningBalanceService::class)->reverse($batch, 'Too late', $this->user->id);
            $this->fail('reversal should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been settled', $e->getMessage());
        }
        $this->assertSame('posted', $batch->refresh()->status);

        $settlement->unapply($application, 'Undo', $this->user->id);
        app(OpeningBalanceService::class)->reverse($batch, 'Wrong figures', $this->user->id);

        $this->assertSame('reversed', $batch->refresh()->status);
        foreach (['1100', '2100', '2300', '1153', '3200'] as $code) {
            $this->assertSame(0, $this->familyNet($code), $code);
        }
        $this->assertSame(0, $this->net('2300'));
        $this->assertSame(0, $this->net('1153'));
        $this->assertSame(0, Invoice::query()->where('is_opening', true)->where('status', '!=', 'voided')->count());
        $this->assertSame(0, Payment::query()->where('is_opening', true)->where('status', '!=', 'cancelled')->count());
        $this->assertSame(0, SupplierPayment::query()->where('is_opening', true)->where('status', '!=', 'cancelled')->count());
        // Both the original and the reclassification entries were reversed once.
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'opening_balance_backfill')->whereNull('reversal_of_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as r')->whereColumn('r.reversal_of_id', 'journal_entries.id'))->count());
    }

    public function test_batch_in_a_closed_period_is_dated_today(): void
    {
        $lastMonth = now()->startOfMonth()->subMonthNoOverflow();
        $period = FiscalPeriod::query()->create([
            'fiscal_year_id' => FiscalPeriod::query()->value('fiscal_year_id'),
            'name' => $lastMonth->format('Y-m'),
            'starts_on' => $lastMonth->toDateString(),
            'ends_on' => $lastMonth->copy()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
        $this->legacyBatch(asOf: $lastMonth->toDateString(), withForeign: false);
        $period->update(['status' => 'closed']);

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();

        $entry = JournalEntry::query()->where('source_type', 'opening_balance_backfill')->firstOrFail();
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
        $this->assertSame(5000000, $this->net($this->subaccount('customer', $this->c1->id, 'receivable')));
    }

    public function test_branch_filter_and_limit(): void
    {
        $branch2 = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'فرع ٢', 'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($branch2->id);
        $this->legacyBatch(withForeign: false);
        $this->legacyBatch($branch2, now()->startOfMonth()->addDay()->toDateString(), false);

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true, '--branch' => 'B2'])->assertSuccessful();
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_backfill')->count());
        $this->assertSame($branch2->id, (int) JournalEntry::query()->where('source_type', 'opening_balance_backfill')->value('branch_id'));

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'opening_balance_backfill')->count());
        $this->assertSame(8, Invoice::query()->where('is_opening', true)->count() + Payment::query()->where('is_opening', true)->count() + SupplierPayment::query()->where('is_opening', true)->count());
    }

    public function test_refuses_when_accounting_policy_is_not_approved(): void
    {
        $this->legacyBatch();
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');
        $before = $this->counts();

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    public function test_new_style_batches_are_left_alone(): void
    {
        $service = app(OpeningBalanceService::class);
        $batch = $service->create([
            'as_of_date' => now()->startOfMonth()->toDateString(),
            'lines' => [
                ['account_id' => '1100', 'debit_minor' => 700000, 'party_type' => 'customer', 'party_id' => $this->c1->public_id],
                ['account_id' => '1100', 'credit_minor' => 100000, 'party_type' => 'customer', 'party_id' => $this->c2->public_id],
                ['account_id' => '3200', 'credit_minor' => 600000],
            ],
        ], $this->branch, $this->user->id);
        $service->post($batch, $this->user->id);
        $before = $this->counts();

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    public function test_credit_lines_left_on_the_subaccount_move_to_the_advance_account(): void
    {
        // Posted after subaccounts existed but before credit lines had records: the credit sits
        // on the party subaccount and must move to the advance account.
        $service = app(OpeningBalanceService::class);
        $batch = $service->create([
            'as_of_date' => now()->startOfMonth()->toDateString(),
            'lines' => [
                ['account_id' => '1100', 'credit_minor' => 400000, 'party_type' => 'customer', 'party_id' => $this->c1->public_id],
                ['account_id' => '3200', 'debit_minor' => 400000],
            ],
        ], $this->branch, $this->user->id);
        $batch->load('lines.account');
        $sub = app(PartyLedgerService::class)->receivable($this->c1->id);
        $entry = app(PostingService::class)->post(PostingRequest::make(
            now()->startOfMonth()->toDateString(), $this->branch, 'EGP',
            [
                PostingLine::credit($sub, 400000, 'customer', $this->c1->id),
                PostingLine::debit($this->account('3200'), 400000),
            ],
            'Opening (intermediate)', 'opening_balance', $batch->id, 'currency:EGP',
        ));
        $batch->entries()->attach($entry->id, ['currency_code' => 'EGP']);
        $batch->update(['status' => 'posted']);

        $this->artisan('accounting:backfill-opening-documents', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_backfill')->count());
        $this->assertSame(0, $this->net($sub));
        $this->assertSame(-400000, $this->net('2300'));
        $this->assertSame(1, Payment::query()->where('is_opening', true)->count());
    }
}
