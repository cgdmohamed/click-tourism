<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\AuditEvent;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function chart(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        Account::query()->whereIn('code', ['7901', '6901', '7920'])->delete();
    }

    public function test_dry_run_default_then_apply_is_idempotent_and_audited(): void
    {
        $this->chart();
        $before = Account::query()->count();

        $this->artisan('accounting:ensure-accounts')
            ->expectsOutputToContain('Would create account 7920')
            ->expectsOutputToContain('Would create account 7901')
            ->expectsOutputToContain('Would create account 6901')
            ->assertSuccessful();
        $this->assertSame($before, Account::query()->count());

        $this->artisan('accounting:ensure-accounts', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before + 3, Account::query()->count());
        $this->assertSame('revenue', Account::query()->where('code', '7920')->value('type'));
        $this->assertSame('expense', Account::query()->where('code', '6901')->value('type'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'accounting.accounts_ensured')->count());

        $this->artisan('accounting:ensure-accounts', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before + 3, Account::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'accounting.accounts_ensured')->count());
    }

    public function test_never_touches_existing_accounts_and_skips_1151_to_1154(): void
    {
        $this->chart();
        Account::query()->whereIn('code', ['1151', '1152', '1153', '1154'])->delete();
        Account::query()->create(['code' => '7920', 'name_ar' => 'x', 'name_en' => 'Mine', 'type' => 'revenue', 'is_postable' => true, 'is_active' => true]);

        $this->artisan('accounting:ensure-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Mine', Account::query()->where('code', '7920')->value('name_en'));
        $this->assertSame(0, Account::query()->whereIn('code', ['1151', '1152', '1153', '1154'])->count());
    }

    public function test_fx_alias_only_creates_fx_accounts(): void
    {
        $this->chart();

        $this->artisan('accounting:ensure-fx-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertTrue(Account::query()->where('code', '7901')->exists());
        $this->assertTrue(Account::query()->where('code', '6901')->exists());
        $this->assertFalse(Account::query()->where('code', '7920')->exists());
    }

    public function test_dry_run_and_apply_together_fail(): void
    {
        $this->artisan('accounting:ensure-accounts', ['--apply' => true, '--dry-run' => true])->assertFailed();
    }
}
