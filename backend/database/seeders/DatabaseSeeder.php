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
