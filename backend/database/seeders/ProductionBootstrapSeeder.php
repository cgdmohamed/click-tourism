<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * One-time production reference data, deliberately excluding all demo users
 * and any demo financial transactions.
 */
class ProductionBootstrapSeeder extends Seeder
{
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
        ]);
    }
}
