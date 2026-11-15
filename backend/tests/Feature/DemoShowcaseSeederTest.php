<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The public demo looks like a working company: banks, packages, bookings, money in and out, expenses, leads. */
class DemoShowcaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private DemoShowcaseSeeder $showcase;

    protected function setUp(): void
    {
        parent::setUp();
        // Demo users are never created without an explicit password.
        config(['demo.password' => 'test-only-demo-password']);
        $this->seed(DatabaseSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'accounts.post', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        User::query()->where('email', 'sales@example.com')->first()?->givePermissionTo(['accounts.post']);
        app('App\Modules\Core\Services\SettingService')->set('accounting', 'accounting_policy_approved', 'true');
        app('App\Modules\Core\Services\SettingService')->set('accounting', 'auto_post_bookings', 'false');
        (new DemoDataSeeder(force: true))->run();
        $this->showcase = new DemoShowcaseSeeder(force: true);
        $this->showcase->run();
    }

    public function test_no_step_failed(): void
    {
        $this->assertSame([], $this->showcase->failed);
    }

    public function test_the_company_has_banks_packages_and_a_lived_in_ledger(): void
    {
        $this->assertGreaterThanOrEqual(7, CashAccount::query()->count());
        $this->assertGreaterThanOrEqual(1, CashAccount::query()->where('currency_code', 'USD')->count());
        $this->assertGreaterThanOrEqual(9, TourPackage::query()->count());
        foreach (['umrah', 'hajj', 'domestic', 'international', 'day_trip', 'corporate'] as $category) {
            $this->assertTrue(TourPackage::query()->where('category', $category)->exists(), "no $category package");
        }
        $this->assertGreaterThanOrEqual(15, TourDeparture::query()->count());
        $this->assertGreaterThanOrEqual(18, Booking::query()->count());
        $this->assertGreaterThanOrEqual(15, Customer::query()->count());
        $this->assertGreaterThanOrEqual(15, Supplier::query()->count());
        $this->assertGreaterThanOrEqual(12, Payment::query()->count());
        $this->assertGreaterThanOrEqual(4, SupplierPayment::query()->count());
        $this->assertGreaterThanOrEqual(10, Invoice::query()->where('status', 'issued')->count());
        foreach (['draft', 'submitted', 'approved', 'rejected'] as $state) {
            $this->assertTrue(Expense::query()->where('status', $state)->exists() || $state === 'approved', "no $state expense");
        }
        $this->assertGreaterThanOrEqual(10, Expense::query()->count());
        $this->assertGreaterThanOrEqual(3, FixedAsset::query()->count());
        $this->assertGreaterThanOrEqual(2, EmployeeAdvance::query()->count());
        $this->assertGreaterThanOrEqual(8, Lead::query()->count());
    }

    public function test_every_entry_is_balanced(): void
    {
        JournalEntry::query()->where('status', 'posted')->with('lines')->get()->each(function (JournalEntry $entry) {
            $this->assertSame((int) $entry->lines->sum('base_debit_minor'), (int) $entry->lines->sum('base_credit_minor'), "Journal {$entry->number} is not balanced.");
        });
    }

    public function test_the_main_reports_have_numbers(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        $headers = ['X-Branch-Id' => $branch->public_id];
        $range = ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString(), 'as_of' => now()->toDateString(), 'type' => 'ar'];

        foreach (['trial-balance', 'income-statement', 'balance-sheet', 'aging', 'profitability', 'sales', 'commissions'] as $report) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/reports/'.$report.'?'.http_build_query($range));
            $this->assertSame(200, $response->status(), "$report answered {$response->status()}: ".mb_substr($response->getContent(), 0, 200));
        }
        $income = $this->withHeaders($headers)->getJson('/api/v1/reports/income-statement?'.http_build_query($range))->json('data');
        $this->assertStringContainsString('4100', json_encode($income), 'the income statement shows no service revenue');
        $this->assertStringContainsString('6100', json_encode($income), 'the income statement shows no rent expense');
    }

    public function test_it_runs_once(): void
    {
        $before = Booking::query()->count();
        (new DemoShowcaseSeeder(force: true))->run();

        $this->assertSame($before, Booking::query()->count());
    }
}
