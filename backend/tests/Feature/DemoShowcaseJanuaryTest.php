<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierPayment;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A demo built in the first weeks of a year: the fiscal periods cover the current year only, so rows that
 * are "60 days ago" must be dated no earlier than 1 January or posting is refused and the rest is skipped.
 */
class DemoShowcaseJanuaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        Carbon::setTestNow('2027-01-10 10:00:00');
        parent::setUp();
        config(['demo.password' => 'test-only-demo-password']);
        $this->seed(DatabaseSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'accounts.post', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        User::query()->where('email', 'sales@example.com')->first()?->givePermissionTo(['accounts.post']);
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'false');
        (new DemoDataSeeder(force: true))->run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_section_is_built_ten_days_into_the_year(): void
    {
        $showcase = new DemoShowcaseSeeder(force: true);
        $showcase->run();

        $this->assertSame([], $showcase->failed);
        $this->assertGreaterThanOrEqual(10, Expense::query()->count());
        $this->assertGreaterThanOrEqual(10, Payment::query()->count());
        $this->assertGreaterThanOrEqual(4, SupplierPayment::query()->count());
        $this->assertGreaterThanOrEqual(3, FixedAsset::query()->count());
        $this->assertSame(0, JournalEntry::query()->whereDate('entry_date', '<', '2027-01-01')->count());
    }
}
