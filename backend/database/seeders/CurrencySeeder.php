<?php

namespace Database\Seeders;

use App\Modules\Money\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /** @return list<array{code: string, name: string, symbol: string, exponent: int}> */
    public static function currencies(): array
    {
        return [
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'exponent' => 2],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'ر.س', 'exponent' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exponent' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exponent' => 2],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'exponent' => 2],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exponent' => 3],
        ];
    }

    public function run(): void
    {
        foreach (self::currencies() as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency + ['is_active' => true],
            );
        }
    }
}
