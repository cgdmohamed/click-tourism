<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Treasury\Models\CashAccount;
use Illuminate\Database\Seeder;

/**
 * Neutral starter data (DEC-030): registers the seeded cash and bank GL accounts
 * and creates expense categories mapped to the seeded expense accounts.
 * Idempotent; a client may rename, deactivate or add rows.
 */
class TreasurySeeder extends Seeder
{
    public function run(): void
    {
        $cash = [
            ['code' => '1110', 'kind' => 'cash', 'name_ar' => 'الخزينة الرئيسية', 'name_en' => 'Main cash'],
            ['code' => '1120', 'kind' => 'bank', 'name_ar' => 'الحساب البنكي الرئيسي', 'name_en' => 'Main bank account'],
        ];

        foreach ($cash as $row) {
            $account = Account::query()->where('code', $row['code'])->first();
            if (! $account) {
                continue;
            }

            CashAccount::query()->firstOrCreate(
                ['account_id' => $account->id],
                [
                    'kind' => $row['kind'],
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'currency_code' => $account->currency_code ?? 'EGP',
                    'is_active' => true,
                ],
            );
        }

        $categories = [
            ['code' => 'RENT', 'account' => '6100', 'name_ar' => 'إيجارات', 'name_en' => 'Rent'],
            ['code' => 'UTIL', 'account' => '6200', 'name_ar' => 'مرافق واتصالات', 'name_en' => 'Utilities & Communications'],
            ['code' => 'MKT', 'account' => '6300', 'name_ar' => 'تسويق وإعلان', 'name_en' => 'Marketing & Advertising'],
            ['code' => 'OFFICE', 'account' => '6400', 'name_ar' => 'مصروفات مكتبية وعمومية', 'name_en' => 'Office & General'],
            ['code' => 'BANK', 'account' => '6500', 'name_ar' => 'مصروفات وعمولات بنكية', 'name_en' => 'Bank Charges'],
            ['code' => 'TRAVEL', 'account' => '6600', 'name_ar' => 'انتقالات وسفر', 'name_en' => 'Transport & Travel'],
        ];

        foreach ($categories as $row) {
            $account = Account::query()->where('code', $row['account'])->first();
            if (! $account) {
                continue;
            }

            ExpenseCategory::query()->firstOrCreate(
                ['code' => $row['code']],
                [
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'account_id' => $account->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
