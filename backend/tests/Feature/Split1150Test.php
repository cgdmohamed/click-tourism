<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class Split1150Test extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Branch $branch2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->branch2 = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'فرع ٢', 'name_en' => 'Branch 2',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
    }

    /** Recreate the pre-split state of an existing install. */
    private function legacyChart(): void
    {
        Account::query()->whereIn('code', ['1151', '1152', '1153', '1154'])->delete();
        Account::query()->where('code', '1150')->update(['is_postable' => true]);
        foreach (['employee_advance_account_code', 'employee_custody_account_code', 'supplier_advance_account_code', 'prepaid_expense_account_code'] as $key) {
            app(SettingService::class)->set('accounting', $key, '1150');
        }
    }

    private function post1150(string $sourceType, int $sourceId, int $amount, ?string $partyType, ?int $partyId, ?Branch $branch = null, string $currency = 'EGP', string $rate = '1', string $offset = '1110'): void
    {
        $debit = PostingLine::debit($this->account('1150'), $amount, $partyType, $partyId);
        $credit = PostingLine::credit($this->account($offset), $amount, $partyType, $partyId);
        if ($currency !== 'EGP') {
            $debit = $debit->in($currency, $rate);
            $credit = PostingLine::credit($this->account($offset), (int) round($amount * (float) $rate));
        }

        app(PostingService::class)->post(PostingRequest::make(
            now(), $branch ?? $this->branch, 'EGP', [$debit, $credit], 'legacy',
            sourceType: $sourceType, sourceId: $sourceId, event: 'legacy:'.$sourceId,
        ));
    }

    private function seedLegacyBalances(): void
    {
        $this->legacyChart();
        $this->post1150('employee_advance', 1, 100000, 'employee', 11);
        $this->post1150('employee_custody', 2, 50000, 'employee', 12);
        $this->post1150('supplier_payment', 3, 3000, 'supplier', 7, null, 'USD', '50', '1120');
        $this->post1150('expense', 4, 20000, null, null);
        $this->post1150('expense', 5, 10000, null, null, $this->branch2);
    }

    private function balance(string $code, ?int $branchId = null, ?string $currency = null): int
    {
        $query = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('journal_lines.account_id', $this->account($code)->id);
        if ($branchId) {
            $query->where('journal_lines.branch_id', $branchId);
        }
        if ($currency) {
            $query->where('journal_lines.currency_code', $currency);
        }

        return (int) $query->sum('journal_lines.debit_minor') - (int) $query->sum('journal_lines.credit_minor');
    }

    private function baseBalance(string $code): int
    {
        $query = JournalLine::query()->where('account_id', $this->account($code)->id);

        return (int) $query->sum('base_debit_minor') - (int) $query->sum('base_credit_minor');
    }

    public function test_seeded_chart_has_non_postable_parent_and_four_postable_children(): void
    {
        $parent = $this->account('1150');
        $this->assertFalse($parent->is_postable);
        $this->assertSame('asset', $parent->type);

        foreach (['1151' => 'سلف الموظفين', '1152' => 'عهد الموظفين', '1153' => 'دفعات مقدمة للموردين', '1154' => 'مصروفات مدفوعة مقدماً'] as $code => $nameAr) {
            $child = $this->account($code);
            $this->assertTrue($child->is_postable, $code);
            $this->assertSame('asset', $child->type);
            $this->assertSame($parent->id, $child->parent_id);
            $this->assertSame($nameAr, $child->name_ar);
            $this->assertNotEmpty($child->name_en);
        }
    }

    public function test_seeder_rerun_does_not_make_a_live_1150_non_postable(): void
    {
        $this->legacyChart();
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertTrue($this->account('1150')->is_postable);
    }

    public function test_new_defaults_point_to_the_dedicated_accounts(): void
    {
        $settings = app(SettingService::class);
        $this->assertSame('1151', $settings->get('accounting', 'employee_advance_account_code'));
        $this->assertSame('1152', $settings->get('accounting', 'employee_custody_account_code'));
        $this->assertSame('1153', $settings->get('accounting', 'supplier_advance_account_code'));
        $this->assertSame('1154', $settings->get('accounting', 'prepaid_expense_account_code'));
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->seedLegacyBalances();
        $entries = JournalEntry::query()->count();
        $audits = AuditEvent::query()->count();

        $this->artisan('accounting:split-1150')->assertSuccessful();
        $this->artisan('accounting:split-1150', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($entries, JournalEntry::query()->count());
        $this->assertSame($audits, AuditEvent::query()->count());
        $this->assertNull(Account::query()->where('code', '1151')->first());
        $this->assertTrue($this->account('1150')->is_postable);
        $this->assertSame('1150', app(SettingService::class)->get('accounting', 'employee_advance_account_code'));
    }

    public function test_apply_reclassifies_mixed_balances_and_is_idempotent(): void
    {
        $this->seedLegacyBalances();
        $baseBefore = $this->baseBalance('1150');

        $this->artisan('accounting:split-1150', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, $this->balance('1150'));
        $this->assertSame(0, $this->baseBalance('1150'));
        $this->assertSame(100000, $this->balance('1151'));
        $this->assertSame(50000, $this->balance('1152'));
        $this->assertSame(3000, $this->balance('1153', null, 'USD'));
        $this->assertSame(20000, $this->balance('1154', $this->branch->id));
        $this->assertSame(10000, $this->balance('1154', $this->branch2->id));
        $this->assertSame($baseBefore, $this->baseBalance('1151') + $this->baseBalance('1152') + $this->baseBalance('1153') + $this->baseBalance('1154'));
        $this->assertSame(150000, $this->baseBalance('1153'));

        // Party tags survive on the child lines.
        $supplierLine = JournalLine::query()->where('account_id', $this->account('1153')->id)->firstOrFail();
        $this->assertSame('supplier', $supplierLine->party_type);
        $this->assertSame(7, (int) $supplierLine->party_id);
        $this->assertSame('USD', $supplierLine->currency_code);
        $this->assertSame(11, (int) JournalLine::query()->where('account_id', $this->account('1151')->id)->value('party_id'));

        // Ordinary balanced entries, one per branch, traceable.
        $entries = JournalEntry::query()->where('source_type', 'account_reclassification')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['split-1150:1', 'split-1150:2'], $entries->pluck('event')->all());
        foreach ($entries as $entry) {
            $this->assertSame('posted', $entry->status);
            $this->assertEquals($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));
            $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
        }

        // Group header locked, settings moved, audited.
        $this->assertFalse($this->account('1150')->is_postable);
        $this->assertSame($this->account('1150')->id, $this->account('1151')->parent_id);
        $settings = app(SettingService::class);
        $this->assertSame('1151', $settings->get('accounting', 'employee_advance_account_code'));
        $this->assertSame('1152', $settings->get('accounting', 'employee_custody_account_code'));
        $this->assertSame('1153', $settings->get('accounting', 'supplier_advance_account_code'));
        $this->assertSame('1154', $settings->get('accounting', 'prepaid_expense_account_code'));
        $this->assertTrue(AuditEvent::query()->where('action', 'account.split_1150')->exists());

        // Idempotent.
        $entryCount = JournalEntry::query()->count();
        $this->artisan('accounting:split-1150', ['--apply' => true])->assertSuccessful();
        $this->assertSame($entryCount, JournalEntry::query()->count());
        $this->assertSame(100000, $this->balance('1151'));
    }

    public function test_custom_setting_values_are_preserved(): void
    {
        $this->seedLegacyBalances();
        app(SettingService::class)->set('accounting', 'employee_custody_account_code', '1110');
        app(SettingService::class)->set('accounting', 'prepaid_expense_account_code', '1120');

        $this->artisan('accounting:split-1150', ['--apply' => true])->assertSuccessful();

        $settings = app(SettingService::class);
        $this->assertSame('1110', $settings->get('accounting', 'employee_custody_account_code'));
        $this->assertSame('1120', $settings->get('accounting', 'prepaid_expense_account_code'));
        $this->assertSame('1151', $settings->get('accounting', 'employee_advance_account_code'));
    }

    public function test_refuses_when_accounting_policy_is_not_approved(): void
    {
        $this->seedLegacyBalances();
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');

        $this->artisan('accounting:split-1150', ['--apply' => true])->assertFailed();

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'account_reclassification')->count());
        $this->assertTrue($this->account('1150')->is_postable);
    }

    public function test_group_header_stays_postable_while_a_draft_still_posts_to_it(): void
    {
        $this->seedLegacyBalances();
        app(PostingService::class)->draft(PostingRequest::make(
            now(), $this->branch, 'EGP',
            [PostingLine::debit($this->account('1150'), 500), PostingLine::credit($this->account('1110'), 500)],
            'pending draft',
        ));

        $this->artisan('accounting:split-1150', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, $this->balance('1150'));
        $this->assertTrue($this->account('1150')->is_postable);
    }

    public function test_new_install_flows_use_the_new_accounts(): void
    {
        $posting = app(PostingService::class);
        $this->expectException(\DomainException::class);
        $posting->post(PostingRequest::make(
            now(), $this->branch, 'EGP',
            [PostingLine::debit($this->account('1150'), 100), PostingLine::credit($this->account('1110'), 100)],
        ));
    }
}
