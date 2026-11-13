<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\FxRevaluationRun;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FiscalCloseService;
use App\Modules\Accounting\Services\PeriodEndChecklistService;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Accounting\Services\RevaluationService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Services\PartyLedgerService;
use App\Modules\Treasury\Models\CashAccount;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class FxRevaluationTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 10, 12));
        $this->buildAccounting();
        $this->period = FiscalPeriod::query()->firstOrFail();
        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'true');
        $this->rate('50', '2026-03-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rate(string $rate, string $date): void
    {
        app(FxService::class)->upsertRate('USD', $rate, $date);
    }

    private function nextPeriod(string $status = 'open'): FiscalPeriod
    {
        return FiscalPeriod::query()->create([
            'fiscal_year_id' => $this->period->fiscal_year_id,
            'name' => $this->period->ends_on->copy()->addDay()->format('Y-m'),
            'starts_on' => $this->period->ends_on->copy()->addDay()->toDateString(),
            'ends_on' => $this->period->ends_on->copy()->addDay()->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    private function postEntry(array $lines, ?string $date = null): JournalEntry
    {
        return app(PostingService::class)->post(PostingRequest::make(
            $date ?? '2026-03-05', $this->branch, 'EGP', $lines, 'usd txn', actorId: $this->user->id,
        ));
    }

    /** USD 1,000.00 receivable booked at 50 (EGP 50,000.00). */
    private function usdReceivable(): Account
    {
        $receivable = app(PartyLedgerService::class)->receivable($this->customer()->id);
        $this->postEntry([
            PostingLine::debit($receivable, 100000)->in('USD', '50'),
            PostingLine::credit($this->account('4100'), 5000000),
        ]);

        return $receivable;
    }

    private function usdPayable(): Account
    {
        $payable = app(PartyLedgerService::class)->payable($this->supplier()->id);
        $this->postEntry([
            PostingLine::debit($this->account('5100'), 2500000),
            PostingLine::credit($payable, 50000)->in('USD', '50'),
        ]);

        return $payable;
    }

    private function usdCash(): Account
    {
        $account = Account::query()->create([
            'code' => '1115', 'name_ar' => 'بنك دولار', 'name_en' => 'USD Bank', 'type' => 'asset',
            'is_postable' => true, 'is_active' => true, 'currency_code' => 'USD',
        ]);
        CashAccount::query()->create([
            'account_id' => $account->id, 'branch_id' => $this->branch->id, 'kind' => 'bank',
            'name_ar' => 'بنك', 'name_en' => 'USD Bank', 'currency_code' => 'USD', 'is_active' => true,
        ]);
        $this->postEntry([
            PostingLine::debit($account, 20000)->in('USD', '50'),
            PostingLine::credit($this->account('4100'), 1000000),
        ]);

        return $account;
    }

    private function baseBalance(Account $account): int
    {
        return (int) DB::table('journal_lines')->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(base_debit_minor - base_credit_minor),0) as b')->value('b');
    }

    private function nativeBalance(Account $account): int
    {
        return (int) DB::table('journal_lines')->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit_minor - credit_minor),0) as b')->value('b');
    }

    private function service(): RevaluationService
    {
        return app(RevaluationService::class);
    }

    private function asAccountant(): array
    {
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
        Permission::query()->firstOrCreate(['name' => 'periods.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'periods.close', 'guard_name' => 'web']);
        $this->user->givePermissionTo(['periods.view', 'periods.close']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->user, ['*']);

        return ['X-Branch-Id' => $this->branch->public_id];
    }

    public function test_the_estimate_is_reviewed_before_confirmation_and_confirming_twice_posts_once(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $headers = $this->asAccountant();
        $url = '/api/v1/fiscal-periods/'.$this->period->id.'/revaluation';

        $this->withHeaders($headers)->getJson($url)->assertOk()
            ->assertJsonPath('data.rows.0.currency', 'USD')
            ->assertJsonPath('data.rows.0.difference_minor', 200000)
            ->assertJsonPath('data.totals.gain_minor', 200000)
            ->assertJsonPath('data.totals.loss_minor', 0)
            ->assertJsonPath('data.run_status', null);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());

        $this->withHeaders($headers + ['Idempotency-Key' => 'reval-1'])->postJson($url)->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->withHeaders($headers + ['Idempotency-Key' => 'reval-2'])->postJson($url)->assertOk()
            ->assertJsonPath('data.status', 'already_posted');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fx_revaluation')->whereNull('reversal_of_id')->count());
    }

    public function test_confirming_requires_the_close_permission_and_an_enabled_approved_policy(): void
    {
        $headers = $this->asAccountant();
        $url = '/api/v1/fiscal-periods/'.$this->period->id.'/revaluation';

        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');
        $this->withHeaders($headers + ['Idempotency-Key' => 'k1'])->postJson($url)->assertStatus(422);

        $this->user->revokePermissionTo('periods.close');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withHeaders($headers + ['Idempotency-Key' => 'k2'])->postJson($url)->assertForbidden();
        $this->withHeaders($headers)->getJson($url)->assertOk();
    }

    public function test_receivable_gain_is_posted_base_only_to_the_unrealized_gain_account(): void
    {
        $receivable = $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        $result = $this->service()->revalue($this->period, $this->branch, $this->user->id);

        $this->assertSame('posted', $result['status']);
        $entry = $result['entry'];
        $this->assertSame('fx_revaluation', $entry->source_type);
        $this->assertSame($this->period->id, (int) $entry->source_id);
        $this->assertSame('reval:'.$this->branch->id, $entry->event);
        $this->assertSame('2026-03-31', $entry->entry_date->toDateString());
        $this->assertSame(5200000, $this->baseBalance($receivable));
        $this->assertSame(100000, $this->nativeBalance($receivable), 'native balance is untouched');
        $this->assertSame(-200000, $this->baseBalance($this->account('7901')));
        $this->assertSame((int) $entry->lines->sum('base_debit_minor'), (int) $entry->lines->sum('base_credit_minor'));
        $this->assertSame(0, (int) $entry->lines->sum('debit_minor') + (int) $entry->lines->sum('credit_minor'));
    }

    public function test_payable_and_cash_gain_and_loss_are_posted(): void
    {
        $payable = $this->usdPayable();
        $cash = $this->usdCash();
        $this->rate('52', '2026-03-31');

        $this->service()->revalue($this->period, $this->branch, $this->user->id);

        // Payable (credit) grows 50 USD * 2 = 1,000.00 EGP: loss. Cash grows 200 USD * 2 = 4,000.00: gain.
        $this->assertSame(-2600000, $this->baseBalance($payable));
        $this->assertSame(1040000, $this->baseBalance($cash));
        $this->assertSame(100000, $this->baseBalance($this->account('6901')));
        $this->assertSame(-40000, $this->baseBalance($this->account('7901')));
    }

    public function test_the_year_end_check_warns_about_unrevalued_balances_without_blocking(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $year = FiscalYear::query()->findOrFail($this->period->fiscal_year_id);
        $close = app(FiscalCloseService::class);

        $before = $close->checks($year);
        $this->assertSame('fx_revaluation', $before['warnings'][0]['code']);
        $this->assertNotContains('fx_revaluation', collect($before['checks'])->pluck('code')->all(), 'revaluation is a warning, never a blocking check');

        $this->service()->revalue($this->period, $this->branch, $this->user->id);
        $year->refresh();
        $yearEnd = $year->ends_on->toDateString();
        $this->rate('52', $yearEnd);

        $after = $close->checks($year);
        $this->assertSame([], $after['warnings'], 'revalued balances raise no warning');
    }

    public function test_receivable_loss_when_the_closing_rate_falls(): void
    {
        $receivable = $this->usdReceivable();
        $this->rate('48', '2026-03-31');

        $this->service()->revalue($this->period, $this->branch, $this->user->id);

        $this->assertSame(4800000, $this->baseBalance($receivable));
        $this->assertSame(200000, $this->baseBalance($this->account('6901')));
        $this->assertSame(0, $this->baseBalance($this->account('7901')));
    }

    public function test_preview_reports_native_carrying_revalued_and_difference(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        $rows = $this->service()->preview('2026-03-31', $this->branch);

        $this->assertCount(1, $rows);
        $this->assertSame(100000, $rows[0]['native_minor']);
        $this->assertSame(5000000, $rows[0]['carrying_base_minor']);
        $this->assertSame(5200000, $rows[0]['revalued_base_minor']);
        $this->assertSame(200000, $rows[0]['difference_minor']);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count(), 'preview posts nothing');
    }

    public function test_rerunning_a_period_is_idempotent(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        $this->service()->revalue($this->period, $this->branch, $this->user->id);
        $second = $this->service()->revalue($this->period, $this->branch, $this->user->id);

        $this->assertSame('already_posted', $second['status']);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());
        $this->assertSame(1, FxRevaluationRun::query()->count());
    }

    public function test_reversal_stays_pending_until_the_next_period_is_open_then_posts_on_its_first_day(): void
    {
        $receivable = $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        $result = $this->service()->revalue($this->period, $this->branch, $this->user->id);
        $this->assertNull($result['reversal']);
        $run = FxRevaluationRun::query()->firstOrFail();
        $this->assertSame('reversal_pending', $run->status);

        // Next period does not exist yet: nothing posted.
        Carbon::setTestNow(Carbon::create(2026, 4, 1, 1));
        $this->assertSame([], $this->service()->postPendingReversals());

        $this->nextPeriod();
        $posted = $this->service()->postPendingReversals();

        $this->assertCount(1, $posted);
        $reversal = $posted[0];
        $this->assertSame('2026-04-01', $reversal->entry_date->toDateString());
        $this->assertSame($result['entry']->id, (int) $reversal->reversal_of_id);
        $this->assertSame('reval_reversal:'.$this->branch->id, $reversal->event);
        $this->assertSame(5000000, $this->baseBalance($receivable));
        $this->assertSame(0, $this->baseBalance($this->account('7901')));
        $this->assertSame('reversed', $run->refresh()->status);
        $this->assertSame([], $this->service()->postPendingReversals(), 'reversal is not duplicated');
    }

    public function test_reversal_is_not_posted_before_the_next_period_starts(): void
    {
        $this->usdReceivable();
        $this->nextPeriod();
        $this->rate('52', '2026-03-31');
        Carbon::setTestNow(Carbon::create(2026, 3, 31, 23, 30));

        $result = $this->service()->revalue($this->period, $this->branch);

        $this->assertNull($result['reversal']);
        $this->assertSame('reversal_pending', FxRevaluationRun::query()->firstOrFail()->status);
    }

    public function test_without_auto_reverse_the_run_stays_posted_and_the_next_revaluation_is_incremental(): void
    {
        app(SettingService::class)->set('accounting', 'fx_revaluation_auto_reverse', 'false');
        $receivable = $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $this->service()->revalue($this->period, $this->branch);
        $this->assertSame('posted', FxRevaluationRun::query()->firstOrFail()->status);
        $this->assertSame([], $this->service()->postPendingReversals());

        $april = $this->nextPeriod();
        $this->rate('53', '2026-04-30');
        $this->service()->revalue($april, $this->branch);

        $this->assertSame(5300000, $this->baseBalance($receivable));
    }

    public function test_a_new_revaluation_is_blocked_while_an_earlier_reversal_is_pending(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $this->service()->revalue($this->period, $this->branch);
        $april = $this->nextPeriod();
        $this->rate('53', '2026-04-30');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('reversal is still pending');
        $this->service()->revalue($april, $this->branch);
    }

    public function test_redo_is_only_possible_after_the_previous_revaluation_is_reversed(): void
    {
        $receivable = $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $first = $this->service()->revalue($this->period, $this->branch, $this->user->id)['entry'];

        app(PostingService::class)->reverse($first, 'wrong rate', $this->user->id);
        $this->rate('51', '2026-03-31');
        $redo = $this->service()->revalue($this->period, $this->branch, $this->user->id);

        $this->assertSame('posted', $redo['status']);
        $this->assertSame('reval:'.$this->branch->id.':2', $redo['entry']->event);
        $this->assertSame(5100000, $this->baseBalance($receivable));
        $this->assertSame(2, FxRevaluationRun::query()->firstOrFail()->attempt);
    }

    public function test_disabled_setting_or_unapproved_policy_blocks_revaluation(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'false');
        try {
            $this->service()->revalue($this->period, $this->branch);
            $this->fail('expected disabled');
        } catch (DomainException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'true');
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');
        try {
            $this->service()->revalue($this->period, $this->branch);
            $this->fail('expected policy block');
        } catch (DomainException $e) {
            $this->assertStringContainsString('policy', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());
    }

    public function test_command_is_a_no_op_when_disabled_and_dry_run_by_default(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');

        $this->artisan('accounting:revalue', ['--period' => '2026-03'])
            ->expectsOutputToContain('Dry run only')->assertSuccessful();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());

        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'false');
        // A disabled setting must hide the dry-run preview too, so the command
        // never shows entries that --apply would refuse.
        $this->artisan('accounting:revalue', ['--period' => '2026-03'])
            ->expectsOutputToContain('nothing previewed or posted')
            ->doesntExpectOutputToContain('Dry run only')
            ->assertSuccessful();
        $this->artisan('accounting:revalue', ['--period' => '2026-03', '--apply' => true])
            ->expectsOutputToContain('nothing previewed or posted')->assertSuccessful();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());

        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');
        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'true');
        $this->artisan('accounting:revalue', ['--period' => '2026-03'])
            ->expectsOutputToContain('nothing previewed or posted')->assertSuccessful();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());

        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
        $this->artisan('accounting:revalue', ['--period' => '2026-03', '--apply' => true])->assertSuccessful();
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());
    }

    public function test_closed_period_cannot_be_revalued(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $this->period->update(['status' => 'closed']);

        try {
            $this->service()->revalue($this->period->refresh(), $this->branch);
            $this->fail('expected closed period rejection');
        } catch (DomainException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }
        $this->assertSame(0, FxRevaluationRun::query()->count());

        $this->artisan('accounting:revalue', ['--period' => '2026-03', '--apply' => true])->assertFailed();
    }

    public function test_missing_unrealized_account_fails_with_a_clear_message_and_helper_creates_it(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $this->account('7901')->delete();

        try {
            $this->service()->revalue($this->period, $this->branch);
            $this->fail('expected missing account');
        } catch (DomainException $e) {
            $this->assertStringContainsString('ensure-accounts', $e->getMessage());
        }

        $this->artisan('accounting:ensure-fx-accounts')->assertSuccessful();
        // Dry run by default: nothing is created until --apply.
        $this->assertSame(0, Account::query()->where('code', '7901')->count());

        $this->artisan('accounting:ensure-fx-accounts')
            ->expectsOutputToContain('Would create account 7901')->assertSuccessful();
        $this->assertSame(0, Account::query()->where('code', '7901')->count());

        $this->artisan('accounting:ensure-fx-accounts', ['--apply' => true])->assertSuccessful();
        $this->assertSame('revenue', $this->account('7901')->type);
        $this->artisan('accounting:ensure-fx-accounts', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, Account::query()->where('code', '7901')->count());

        $this->assertSame('posted', $this->service()->revalue($this->period, $this->branch)['status']);
    }

    public function test_base_currency_only_install_does_nothing(): void
    {
        $this->postEntry([
            PostingLine::debit($this->account('1100'), 10000),
            PostingLine::credit($this->account('4100'), 10000),
        ]);

        $result = $this->service()->revalue($this->period, $this->branch);

        $this->assertSame('nothing_to_revalue', $result['status']);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fx_revaluation')->count());
        $this->assertSame(0, FxRevaluationRun::query()->count());
    }

    public function test_period_end_checklist_reports_revaluation_without_blocking(): void
    {
        $this->usdReceivable();
        $this->rate('52', '2026-03-31');
        $checklist = app(PeriodEndChecklistService::class);

        $find = fn () => collect($checklist->forPeriod($this->period, $this->branch)['checks'])->firstWhere('key', 'revaluation');

        $check = $find();
        $this->assertFalse($check['passed']);
        $this->assertFalse($check['blocking']);

        $this->service()->revalue($this->period, $this->branch);
        $this->assertTrue($find()['passed']);

        app(SettingService::class)->set('accounting', 'fx_revaluation_enabled', 'false');
        $this->assertTrue($find()['passed']);
    }
}
