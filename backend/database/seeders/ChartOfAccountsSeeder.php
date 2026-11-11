<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Minimal chart of accounts required by the posting service.
 *
 * Account codes are referenced by `accounting.*_account_code` settings, so
 * an installation may rename or re-code accounts as long as the settings are
 * updated. Codes here are the defaults shipped with the product.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1100', 'name_ar' => 'مدينون - عملاء', 'name_en' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '1110', 'name_ar' => 'نقدية بالصندوق', 'name_en' => 'Cash on Hand', 'type' => 'asset'],
            ['code' => '1120', 'name_ar' => 'النقدية بالبنك', 'name_en' => 'Bank Account', 'type' => 'asset'],
            ['code' => '1160', 'name_ar' => 'ضريبة القيمة المضافة المدخلة', 'name_en' => 'Input VAT', 'type' => 'asset'],
            ['code' => '1210', 'name_ar' => 'مجمع إهلاك الأصول الثابتة', 'name_en' => 'Accumulated Depreciation', 'type' => 'asset'],
            ['code' => '1300', 'name_ar' => 'الأصول الثابتة', 'name_en' => 'Fixed Assets', 'type' => 'asset'],
            ['code' => '6100', 'name_ar' => 'إيجارات', 'name_en' => 'Rent', 'type' => 'expense'],
            ['code' => '6200', 'name_ar' => 'مرافق واتصالات', 'name_en' => 'Utilities & Communications', 'type' => 'expense'],
            ['code' => '6300', 'name_ar' => 'تسويق وإعلان', 'name_en' => 'Marketing & Advertising', 'type' => 'expense'],
            ['code' => '6400', 'name_ar' => 'مصروفات مكتبية وعمومية', 'name_en' => 'Office & General', 'type' => 'expense'],
            ['code' => '6500', 'name_ar' => 'مصروفات وعمولات بنكية', 'name_en' => 'Bank Charges', 'type' => 'expense'],
            ['code' => '6600', 'name_ar' => 'انتقالات وسفر', 'name_en' => 'Transport & Travel', 'type' => 'expense'],
            ['code' => '6800', 'name_ar' => 'مصروف الإهلاك', 'name_en' => 'Depreciation Expense', 'type' => 'expense'],
            ['code' => '6910', 'name_ar' => 'خسائر انخفاض قيمة الأصول', 'name_en' => 'Asset Impairment Loss', 'type' => 'expense'],
            ['code' => '6915', 'name_ar' => 'خسائر استبعاد الأصول', 'name_en' => 'Loss on Asset Disposal', 'type' => 'expense'],
            ['code' => '1150', 'name_ar' => 'مصروفات مدفوعة مقدماً', 'name_en' => 'Prepaid Expenses', 'type' => 'asset'],
            ['code' => '2100', 'name_ar' => 'دائنون - موردون', 'name_en' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => '2200', 'name_ar' => 'ضريبة القيمة المضافة المستحقة', 'name_en' => 'VAT Payable', 'type' => 'liability'],
            ['code' => '2300', 'name_ar' => 'دفعات مقدمة من العملاء', 'name_en' => 'Customer Advances', 'type' => 'liability'],
            ['code' => '2400', 'name_ar' => 'إيرادات غير مكتسبة', 'name_en' => 'Unearned Revenue', 'type' => 'liability'],
            ['code' => '3100', 'name_ar' => 'أرباح محتجزة', 'name_en' => 'Retained Earnings', 'type' => 'equity'],
            ['code' => '3200', 'name_ar' => 'رأس المال', 'name_en' => "Owner's Capital", 'type' => 'equity'],
            ['code' => '4100', 'name_ar' => 'إيرادات الخدمات', 'name_en' => 'Service Revenue', 'type' => 'revenue'],
            ['code' => '5100', 'name_ar' => 'تكلفة الخدمات', 'name_en' => 'Cost of Services', 'type' => 'expense'],
            ['code' => '6900', 'name_ar' => 'خسائر فروق العملة', 'name_en' => 'FX Loss', 'type' => 'expense'],
            ['code' => '6901', 'name_ar' => 'خسائر فروق العملة غير المحققة', 'name_en' => 'Unrealized FX Loss', 'type' => 'expense'],
            ['code' => '6999', 'name_ar' => 'فروق تقريب العملة', 'name_en' => 'FX Rounding Difference', 'type' => 'expense'],
            ['code' => '7900', 'name_ar' => 'أرباح فروق العملة', 'name_en' => 'FX Gain', 'type' => 'revenue'],
            ['code' => '7901', 'name_ar' => 'أرباح فروق العملة غير المحققة', 'name_en' => 'Unrealized FX Gain', 'type' => 'revenue'],
            ['code' => '7910', 'name_ar' => 'أرباح استبعاد الأصول', 'name_en' => 'Gain on Asset Disposal', 'type' => 'revenue'],
        ];

        foreach ($accounts as $account) {
            Account::query()->updateOrCreate(
                ['code' => $account['code']],
                $account + ['is_postable' => true, 'is_active' => true],
            );
        }
    }
}
