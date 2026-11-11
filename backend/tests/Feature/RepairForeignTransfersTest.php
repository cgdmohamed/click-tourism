<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Services\SettingService;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Models\Transfer;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class RepairForeignTransfersTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private CashAccount $usd;

    private CashAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        $treasury = app(TreasuryService::class);
        $this->usd = $treasury->register(['kind' => 'cash', 'name_ar' => 'USD', 'name_en' => 'USD cash', 'currency' => 'USD', 'account_code' => '1130']);
        $this->bank = $treasury->register(['kind' => 'bank', 'name_ar' => 'EGP', 'name_en' => 'EGP bank', 'currency' => 'EGP', 'account_code' => '1120']);

        // 1,000.00 USD at 50 funded into the USD cash account.
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'USD',
            lines: [
                PostingLine::debit($this->account('1130'), 100000),
                PostingLine::credit($this->account('3200'), 100000),
            ],
            fxRate: '50',
            fxRateDate: now()->toDateString(),
        ));
    }

    /** Post a transfer the way the engine did before per-line currencies: every line in the base currency. */
    private function legacyTransfer(?string $date = null, string $number = 'TRF-LEG-1'): Transfer
    {
        $date ??= now()->toDateString();

        $transfer = Transfer::query()->create([
            'number' => $number,
            'branch_id' => $this->branch->id,
            'from_cash_account_id' => $this->usd->id,
            'to_cash_account_id' => $this->bank->id,
            'transfer_date' => $date,
            'from_currency' => 'USD',
            'from_amount_minor' => 10000,
            'from_fx_rate' => '50',
            'to_currency' => 'EGP',
            'to_amount_minor' => 520000,
            'to_fx_rate' => '1',
            'fee_minor' => 0,
            'status' => 'posted',
        ]);

        $entry = app(PostingService::class)->post(PostingRequest::make(
            entryDate: $date,
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account('1120'), 520000),
                PostingLine::credit($this->account('1130'), 500000),
                PostingLine::credit($this->account('7900'), 20000),
            ],
            sourceType: 'transfer',
            sourceId: $transfer->id,
            event: 'posted',
            fxRate: '1',
            fxRateDate: $date,
        ));
        $transfer->update(['journal_entry_id' => $entry->id]);

        return $transfer->fresh();
    }

    /** Debit minus credit of the account's lines in one currency. */
    private function native(string $code, string $currency): int
    {
        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $this->account($code)->id)
            ->where('jl.currency_code', $currency)
            ->selectRaw('COALESCE(SUM(jl.debit_minor - jl.credit_minor), 0) as n')->value('n');
    }

    /** @return array<int, array{int, int}> base debit/credit totals per account id */
    private function trialBalance(): array
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, SUM(jl.base_debit_minor) - SUM(jl.base_credit_minor) as net')
            ->pluck('net', 'account_id')
            ->map(fn ($v) => (int) $v)->filter()->all();
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $transfer = $this->legacyTransfer();
        $entries = JournalEntry::query()->count();

        $this->artisan('accounting:repair-foreign-transfers')
            ->expectsOutputToContain('TRF-LEG-1')
            ->expectsOutputToContain('1 transfer(s) would be repaired')
            ->assertSuccessful();
        $this->artisan('accounting:repair-foreign-transfers', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($entries, JournalEntry::query()->count());
        $this->assertNull($transfer->fresh()->repaired_at);
        $this->assertSame(0, AuditEvent::query()->where('action', 'transfer.repaired')->count());
        // Legacy shape: the USD account was never debited in USD.
        $this->assertSame(100000, $this->native('1130', 'USD'));
    }

    public function test_apply_repairs_per_currency_balances_and_keeps_the_base_ledger(): void
    {
        $transfer = $this->legacyTransfer();
        $originalId = $transfer->journal_entry_id;
        $trial = $this->trialBalance();

        $this->assertSame(-500000, $this->native('1130', 'EGP'));

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])
            ->expectsOutputToContain('1 repaired, 0 failed')
            ->assertSuccessful();

        $this->assertSame(90000, $this->native('1130', 'USD'));
        $this->assertSame(0, $this->native('1130', 'EGP'));
        $this->assertSame(520000, $this->native('1120', 'EGP'));

        // The base ledger is unchanged account by account, hence in total.
        $this->assertSame($trial, $this->trialBalance());
        $this->assertSame(0, array_sum($this->trialBalance()));

        $transfer = $transfer->fresh();
        $this->assertNotNull($transfer->repaired_at);
        $this->assertSame($originalId, $transfer->journal_entry_id);

        $original = JournalEntry::query()->findOrFail($originalId);
        $reversal = JournalEntry::query()->where('reversal_of_id', $originalId)->firstOrFail();
        $this->assertSame('Repair legacy cross-currency transfer', $reversal->reversal_reason);
        $this->assertSame('posted', $original->status);

        $repair = JournalEntry::query()->findOrFail($transfer->repair_journal_entry_id);
        $this->assertSame('transfer', $repair->source_type);
        $this->assertSame($transfer->id, (int) $repair->source_id);
        $this->assertSame('repair', $repair->event);
        $this->assertSame(now()->toDateString(), $repair->entry_date->toDateString());
        $this->assertSame($repair->lines->sum('base_debit_minor'), $repair->lines->sum('base_credit_minor'));

        $audit = AuditEvent::query()->where('action', 'transfer.repaired')->firstOrFail();
        $this->assertNull($audit->actor_id);
    }

    public function test_rerunning_is_a_no_op(): void
    {
        $this->legacyTransfer();
        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertSuccessful();
        $entries = JournalEntry::query()->count();

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])
            ->expectsOutputToContain('No legacy cross-currency transfers need repair.')
            ->assertSuccessful();

        $this->assertSame($entries, JournalEntry::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'transfer.repaired')->count());
    }

    public function test_a_correctly_posted_transfer_is_not_selected(): void
    {
        app(TreasuryService::class)->transfer([
            'from_cash_account_id' => $this->usd->public_id,
            'to_cash_account_id' => $this->bank->public_id,
            'transfer_date' => now()->toDateString(),
            'amount_minor' => 10000,
            'to_amount_minor' => 520000,
            'from_fx_rate' => '50',
        ], $this->branch);
        $entries = JournalEntry::query()->count();

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])
            ->expectsOutputToContain('No legacy cross-currency transfers need repair.')
            ->assertSuccessful();

        $this->assertSame($entries, JournalEntry::query()->count());
    }

    public function test_reversing_a_repaired_transfer_reverses_the_replacement_entry(): void
    {
        $transfer = $this->legacyTransfer();
        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertSuccessful();
        $trial = $this->trialBalance();
        $repairId = $transfer->fresh()->repair_journal_entry_id;

        $reversed = app(TreasuryService::class)->reverse($transfer->fresh(), 'Wrong account');

        $this->assertSame('reversed', $reversed->status);
        $this->assertSame($repairId, JournalEntry::query()->findOrFail($reversed->reversal_journal_entry_id)->reversal_of_id);
        $this->assertSame(100000, $this->native('1130', 'USD'));
        $this->assertSame(0, $this->native('1130', 'EGP'));
        $this->assertSame(0, $this->native('1120', 'EGP'));
        $this->assertNotSame($trial, $this->trialBalance());
        $this->assertSame(0, array_sum($this->trialBalance()));

        // Exactly once.
        $this->expectException(\DomainException::class);
        app(TreasuryService::class)->reverse($transfer->fresh(), 'Again');
    }

    public function test_a_transfer_in_a_closed_period_is_replaced_today(): void
    {
        $year = FiscalYear::query()->create([
            'name' => 'Prior',
            'starts_on' => Carbon::now()->subYear()->startOfYear()->toDateString(),
            'ends_on' => Carbon::now()->subYear()->endOfYear()->toDateString(),
        ]);
        $period = FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'name' => 'Prior-01',
            'starts_on' => Carbon::now()->subYear()->startOfYear()->toDateString(),
            'ends_on' => Carbon::now()->subYear()->startOfYear()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
        $date = $period->starts_on->toDateString();
        $transfer = $this->legacyTransfer($date);
        $period->update(['status' => 'closed']);

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertSuccessful();

        $transfer = $transfer->fresh();
        $this->assertNotNull($transfer->repaired_at);
        $reversal = JournalEntry::query()->where('reversal_of_id', $transfer->journal_entry_id)->firstOrFail();
        $this->assertSame(now()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertSame(now()->toDateString(), JournalEntry::query()->findOrFail($transfer->repair_journal_entry_id)->entry_date->toDateString());
        $this->assertSame(90000, $this->native('1130', 'USD'));
    }

    public function test_an_open_period_transfer_keeps_its_original_date(): void
    {
        $date = now()->startOfMonth()->toDateString();
        $transfer = $this->legacyTransfer($date);

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertSuccessful();

        $repair = JournalEntry::query()->findOrFail($transfer->fresh()->repair_journal_entry_id);
        $this->assertSame($date, $repair->entry_date->toDateString());
    }

    public function test_it_refuses_to_run_until_the_accounting_policy_is_approved(): void
    {
        $this->legacyTransfer();
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'false');

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertFailed();

        $this->assertSame(0, JournalEntry::query()->where('event', 'repair')->count());
    }

    public function test_apply_and_dry_run_together_are_rejected(): void
    {
        $this->legacyTransfer();

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true, '--dry-run' => true])->assertFailed();
    }

    public function test_branch_and_limit_filters_narrow_the_selection(): void
    {
        $first = $this->legacyTransfer(null, 'TRF-LEG-1');
        $second = $this->legacyTransfer(null, 'TRF-LEG-2');

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true, '--branch' => 'NOPE'])->assertFailed();
        $this->assertNull($first->fresh()->repaired_at);

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true, '--branch' => 'HQ', '--limit' => 1])
            ->expectsOutputToContain('1 repaired')
            ->assertSuccessful();

        $this->assertNotNull($first->fresh()->repaired_at);
        $this->assertNull($second->fresh()->repaired_at);

        $this->artisan('accounting:repair-foreign-transfers', ['--apply' => true])->assertSuccessful();
        $this->assertNotNull($second->fresh()->repaired_at);
        $this->assertSame(80000, $this->native('1130', 'USD'));
    }
}
