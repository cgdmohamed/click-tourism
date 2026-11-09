<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to installation settings. Values are stored in the `settings`
 * table so a single codebase serves every customer (ADR-0010).
 *
 * Items marked "PENDING ACCOUNTANT APPROVAL" are configurable on purpose:
 * the accounting treatment is not guessed in code, it is chosen per install.
 */
class SettingService
{
    public const DEFAULTS = [
        'general' => [
            'company_name_ar' => 'نظام إدارة السياحة',
            'company_name_en' => 'Tourism ERP',
            'base_currency' => 'EGP',
            'locale' => 'ar',
            'timezone' => 'Africa/Cairo',
        ],
        'accounting' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-001): when revenue is recognized.
            'booking_posting_trigger' => 'confirm',
            'auto_post_bookings' => 'true',
            'fiscal_year_start' => '01-01',
            'rounding_policy' => 'half_up',
            'rounding_account_code' => '6999',
            'ar_account_code' => '1100',
            'advance_account_code' => '2300',
            'revenue_account_code' => '4100',
            'unearned_account_code' => '2400',
            'cost_account_code' => '5100',
            'ap_account_code' => '2100',
            // PENDING ACCOUNTANT APPROVAL: supplier advances (AC-PAY-009) are
            // an asset until applied to an obligation. Configurable per install.
            'supplier_advance_account_code' => '1150',
            'tax_payable_account_code' => '2200',
            'fx_gain_account_code' => '7900',
            'fx_loss_account_code' => '6900',
            // PENDING ACCOUNTANT APPROVAL (DEC-031/032/034): provisional account mappings.
            'bank_charges_account_code' => '6500',
            'input_tax_account_code' => '1160',
            'retained_earnings_account_code' => '3100',
        ],
        'treasury' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-031): refuse transfers that overdraw a cash/bank account.
            'block_negative_cash' => 'false',
        ],
        'expenses' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-032): the creator may not approve their own expense.
            'segregation' => 'true',
        ],
        'tax' => [
            'enabled' => 'false',
            'default_rate' => '0',
        ],
        'crm' => [
            'reassign_after_hours' => '24',
            'assignment_strategy' => 'least_loaded',
        ],
        'documents' => [
            'journal_prefix' => 'JV',
            'booking_prefix' => 'BK',
            'payment_prefix' => 'RV',
            'refund_prefix' => 'RF',
            'supplier_payment_prefix' => 'PV',
            'transfer_prefix' => 'TR',
            'expense_prefix' => 'EX',
        ],
    ];

    public function get(string $group, string $key, ?string $default = null): ?string
    {
        $stored = Setting::query()->where('group', $group)->where('key', $key)->value('value');

        if ($stored !== null) {
            return $stored;
        }

        return self::DEFAULTS[$group][$key] ?? $default;
    }

    public function bool(string $group, string $key, bool $default = false): bool
    {
        $value = $this->get($group, $key);

        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $group, string $key, int $default = 0): int
    {
        $value = $this->get($group, $key);

        return $value === null ? $default : (int) $value;
    }

    public function baseCurrency(): string
    {
        return strtoupper((string) $this->get('general', 'base_currency', 'EGP'));
    }

    /** @return array<int, array{group: string, key: string, value: ?string, is_secret: bool}> */
    public function all(): array
    {
        $stored = Setting::query()->orderBy('group')->orderBy('key')->get();
        $result = [];
        $seen = [];

        foreach ($stored as $setting) {
            $seen[$setting->group.'.'.$setting->key] = true;
            $result[] = [
                'group' => $setting->group,
                'key' => $setting->key,
                'value' => $setting->is_secret ? null : $setting->value,
                'is_secret' => (bool) $setting->is_secret,
            ];
        }

        foreach (self::DEFAULTS as $group => $keys) {
            foreach ($keys as $key => $value) {
                if (isset($seen[$group.'.'.$key])) {
                    continue;
                }
                $result[] = [
                    'group' => $group,
                    'key' => $key,
                    'value' => $value,
                    'is_secret' => false,
                ];
            }
        }

        return $result;
    }

    public function set(string $group, string $key, ?string $value, bool $secret = false): Setting
    {
        Cache::forget("settings.{$group}.{$key}");

        return Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value, 'is_secret' => $secret],
        );
    }
}
