<?php

namespace Tests\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Models\Currency;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

/**
 * Minimal accounting fixtures for feature tests.
 *
 * Deliberately avoids the full DatabaseSeeder: tests should fail for the
 * reason under test, not because demo data changed.
 */
trait BuildsAccounting
{
    protected Branch $branch;

    protected User $user;

    private ?Customer $testCustomer = null;

    protected function buildAccounting(): void
    {
        app(SettingService::class)->set('general', 'base_currency', 'EGP');
        app(SettingService::class)->set('accounting', 'approval_status', 'pending_accountant_review');
        // Tests opt in to auto-posting explicitly; production defaults to false
        // until the accountant approves the accounting policy.
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'true');
        app(SettingService::class)->set('accounting', 'schedule_auto_post', 'true');
        // Most tests act as one user; the segregation rule has its own test.
        app(SettingService::class)->set('accounting', 'refund_segregation', 'false');
        // Tax is opt-in per installation; tests that use tax enable it deliberately.
        app(SettingService::class)->set('tax', 'enabled', 'true');

        foreach (['EGP' => 2, 'USD' => 2, 'SAR' => 2, 'KWD' => 3] as $code => $exponent) {
            Currency::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'symbol' => $code, 'exponent' => $exponent, 'is_active' => true],
            );
        }

        $this->branch = Branch::query()->create([
            'code' => 'HQ',
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Head Office',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        Permission::query()->firstOrCreate(['name' => 'accounts.post', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->branches()->attach($this->branch->id);
        Permission::query()->firstOrCreate(
            ['name' => 'accounts.post', 'guard_name' => 'web']
        );
        Permission::query()->firstOrCreate(
            ['name' => 'journal.reverse', 'guard_name' => 'web']
        );
        $this->user->givePermissionTo(['accounts.post', 'journal.reverse']);

        $this->seed(ChartOfAccountsSeeder::class);

        $year = FiscalYear::query()->create([
            'name' => (string) Carbon::now()->year,
            'starts_on' => Carbon::now()->startOfYear()->toDateString(),
            'ends_on' => Carbon::now()->endOfYear()->toDateString(),
        ]);

        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'name' => Carbon::now()->format('Y-m'),
            'starts_on' => Carbon::now()->startOfMonth()->toDateString(),
            'ends_on' => Carbon::now()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }

    protected function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    protected function customer(?Branch $branch = null): Customer
    {
        if ($branch === null && $this->testCustomer !== null) {
            return $this->testCustomer;
        }

        $customer = Customer::query()->create([
            'type' => 'individual',
            'name_ar' => 'عميل تجريبي',
            'name_en' => 'Test Customer',
            'phone' => '01000000000',
            'branch_id' => ($branch ?? $this->branch)->id,
            'credit_limit_minor' => 0,
            'currency_code' => 'EGP',
            'is_active' => true,
        ]);

        return $this->testCustomer = $customer;
    }

    protected function supplier(?Branch $branch = null): Supplier
    {
        return Supplier::query()->create([
            'category' => 'hotel',
            'name_ar' => 'مورد تجريبي',
            'name_en' => 'Test Supplier',
            'phone' => '02000000000',
            'branch_id' => ($branch ?? $this->branch)->id,
            'settlement_currency' => 'EGP',
            'is_active' => true,
        ]);
    }

    /** Ids of an account and all of its descendants (party subaccounts roll up to their control account). */
    protected function accountFamily(string $code): array
    {
        $root = Account::query()->where('code', $code)->firstOrFail();
        $ids = [$root->id];
        $frontier = [$root->id];
        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
