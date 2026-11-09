<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TaxProfileSeeder extends Seeder
{
    public function run(): void
    {
        $taxPayable = Account::query()->where('code', '2200')->first();

        TaxProfile::query()->updateOrCreate(
            ['name' => 'VAT 14% (Egypt)'],
            [
                'rate' => '14.0000',
                'is_inclusive' => false,
                'sales_account_id' => $taxPayable?->id,
                'purchase_account_id' => $taxPayable?->id,
                'effective_from' => Carbon::now()->startOfYear()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
            ],
        );

        TaxProfile::query()->updateOrCreate(
            ['name' => 'No tax'],
            [
                'rate' => '0.0000',
                'is_inclusive' => false,
                'sales_account_id' => null,
                'purchase_account_id' => null,
                'effective_from' => Carbon::now()->startOfYear()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
            ],
        );
    }
}
