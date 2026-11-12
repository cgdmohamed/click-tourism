<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a usable installation. Order matters: settings and branches are
     * needed by every later seeder, and demo data depends on accounts,
     * periods, users and CRM configuration.
     */
    public function run(): void
    {
        // This seeder creates demo users and demo data. Fail BEFORE writing
        // anything unless this is the isolated demo or a local/testing
        // environment. Real installations use `php artisan erp:install`.
        UserSeeder::assertDemoUsersAllowed();

        $this->call([
            SettingSeeder::class,
            BranchSeeder::class,
            CurrencySeeder::class,
            ChartOfAccountsSeeder::class,
            TreasurySeeder::class,
            FiscalYearSeeder::class,
            TaxProfileSeeder::class,
            CrmSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
