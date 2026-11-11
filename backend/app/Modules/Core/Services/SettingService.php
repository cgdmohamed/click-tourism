<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\Account;
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
            // PENDING ACCOUNTANT APPROVAL: auto-posting is disabled by default
            // until the accountant approves the accounting policy. New
            // installations must not post financial entries automatically.
            'auto_post_bookings' => 'false',
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
            // PENDING ACCOUNTANT APPROVAL (DEC-037): interest earned credited on reconciliation.
            'bank_interest_account_code' => '4100',
            'input_tax_account_code' => '1160',
            // PENDING ACCOUNTANT APPROVAL: output tax collected on customer sales.
            'output_tax_account_code' => '2200',
            'retained_earnings_account_code' => '3100',
            // PENDING ACCOUNTANT APPROVAL (DEC-035): employee advances/custody accounts.
            'employee_advance_account_code' => '1150',
            'employee_custody_account_code' => '1150',
            // PENDING ACCOUNTANT APPROVAL (DEC-038): accrual and prepayment policy.
            // Auto-post due recognition events from the daily scheduler; the
            // period-end checklist reports anything that could not post. Disabled
            // by default until the accountant approves the accounting policy.
            'schedule_auto_post' => 'false',
            // PENDING ACCOUNTANT APPROVAL: global accounting policy approval.
            // Auto-posting (bookings, schedules, FX revaluation) remains
            // disabled until an accountant explicitly approves the policy.
            'accounting_policy_approved' => 'false',
            'approved_by' => '',
            'approved_at' => '',
            // Days after a recognition date that an accrual reversal is dated.
            'accrual_reversal_offset_days' => '1',
            'prepaid_expense_account_code' => '1150',
            'deferred_revenue_account_code' => '2400',
            'accrued_liability_account_code' => '2100',
            'accrued_revenue_account_code' => '1100',
            // PENDING ACCOUNTANT APPROVAL (DEC-004): infrastructure only;
            // no commission journal is posted while auto-post remains false.
            'commission_trigger' => 'fully_collected',
            'commission_auto_post' => 'false',
            // PENDING ACCOUNTANT APPROVAL (DEC-008): revaluation posting is
            // disabled until accounts, eligibility and reversal policy sign-off.
            'fx_revaluation_enabled' => 'false',
            'fx_revaluation_auto_reverse' => 'true',
            // PENDING ACCOUNTANT APPROVAL (DEC-025): manager approval is the
            // guarded product policy; zero margin warns but does not auto-post.
            'negative_margin_requires_approval' => 'true',
            'zero_margin_policy' => 'warn',
        ],
        'treasury' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-031): refuse transfers that overdraw a cash/bank account.
            'block_negative_cash' => 'false',
            // PENDING ACCOUNTANT APPROVAL (DEC-037): bank reconciliation matching rules.
            'reconciliation_tolerance_minor' => '0',
            'reconciliation_date_window_days' => '5',
            'reconciliation_auto_match' => 'false',
        ],
        'expenses' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-032): the creator may not approve their own expense.
            'segregation' => 'true',
        ],
        'assets' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-039): fixed-asset policy.
            // Straight-line is the only method implemented. The convention
            // decides whether the purchase month is fully depreciated
            // (month_of_purchase), starts the following month, or is prorated
            // by days in service (prorate_days). All are settings, not code.
            'depreciation_method' => 'straight_line',
            'depreciation_convention' => 'month_of_purchase',
            'capitalization_threshold_minor' => '0',
            'default_useful_life_months' => '60',
            'default_residual_value_minor' => '0',
            // Manual approval by default: depreciation runs are posted only
            // after the run is reviewed and approved by an authorised user.
            'auto_post_depreciation' => 'false',
            // PENDING ACCOUNTANT APPROVAL (DEC-039): provisional GL mappings.
            'asset_account_code' => '1300',
            'accumulated_depreciation_account_code' => '1210',
            'depreciation_expense_account_code' => '6800',
            'disposal_gain_account_code' => '7910',
            'disposal_loss_account_code' => '6915',
            'impairment_loss_account_code' => '6910',
            'acquisition_offset_account_code' => '2100',
            'disposal_proceeds_account_code' => '1120',
        ],
        'tax' => [
            'enabled' => 'false',
            'default_rate' => '0',
        ],
        'crm' => [
            'reassign_after_hours' => '24',
            'assignment_strategy' => 'least_loaded',
            'business_timezone' => 'Africa/Cairo',
            'business_days' => '1,2,3,4,5',
            'business_day_start' => '09:00',
            'business_day_end' => '17:00',
            'reassignment_max_attempts' => '3',
            'meaningful_activity_types' => 'note,call,email,whatsapp,stage_changed',
        ],
        'documents' => [
            'default_language' => 'bilingual',
            // PENDING ACCOUNTANT APPROVAL (DEC-028): unified legal identity is
            // opt-in only after jurisdiction/template approval.
            'booking_invoice_mode' => 'separate',
            'unified_booking_invoice_enabled' => 'false',
            'journal_prefix' => 'JV',
            'booking_prefix' => 'BK',
            'payment_prefix' => 'RV',
            'refund_prefix' => 'RF',
            'supplier_payment_prefix' => 'PV',
            'transfer_prefix' => 'TR',
            'expense_prefix' => 'EX',
            'customer_invoice_prefix' => 'INV',
            'supplier_invoice_prefix' => 'BIL',
            'credit_note_prefix' => 'CN',
            'employee_advance_prefix' => 'EA',
            'employee_custody_prefix' => 'EC',
            'receipt_voucher_prefix' => 'RCV',
            'payment_voucher_prefix' => 'PAY',
            'transfer_voucher_prefix' => 'TV',
            'adjustment_voucher_prefix' => 'ADJ',
            'refund_voucher_prefix' => 'RF',
            'schedule_prefix' => 'SCH',
            'asset_prefix' => 'FA',
            'depreciation_run_prefix' => 'DEP',
        ],
    ];

    /**
     * Required account type for every default account mapping, per group.
     * Writes through the settings API are validated against this map.
     */
    public const ACCOUNT_TYPES = [
        'accounting' => [
            'ar_account_code' => 'asset',
            'ap_account_code' => 'liability',
            'advance_account_code' => 'liability',
            'supplier_advance_account_code' => 'asset',
            'revenue_account_code' => 'revenue',
            'cost_account_code' => 'expense',
            'tax_payable_account_code' => 'liability',
            'output_tax_account_code' => 'liability',
            'input_tax_account_code' => 'asset',
            'retained_earnings_account_code' => 'equity',
            'fx_gain_account_code' => 'revenue',
            'fx_loss_account_code' => 'expense',
            'rounding_account_code' => 'expense',
            'bank_charges_account_code' => 'expense',
            'bank_interest_account_code' => 'revenue',
            'employee_advance_account_code' => 'asset',
            'employee_custody_account_code' => 'asset',
            'prepaid_expense_account_code' => 'asset',
            'deferred_revenue_account_code' => 'liability',
            'accrued_liability_account_code' => 'liability',
            'accrued_revenue_account_code' => 'asset',
            'unearned_account_code' => 'liability',
        ],
        'assets' => [
            'asset_account_code' => 'asset',
            'accumulated_depreciation_account_code' => 'asset',
            'depreciation_expense_account_code' => 'expense',
            'disposal_gain_account_code' => 'revenue',
            'disposal_loss_account_code' => 'expense',
            'impairment_loss_account_code' => 'expense',
            'acquisition_offset_account_code' => 'liability',
            'disposal_proceeds_account_code' => 'asset',
        ],
    ];

    /** Accounting-policy switches that need the accounting.approve_policy permission. */
    public const POLICY_KEYS = ['accounting_policy_approved', 'auto_post_bookings'];

    /**
     * Returns an error message when $value is not a valid account for the
     * mapping, or null when valid / when the key is not an account mapping.
     */
    public function accountMappingError(string $group, string $key, ?string $value): ?string
    {
        if (! str_ends_with($key, '_account_code') || ! in_array($group, ['accounting', 'assets'], true)) {
            return null;
        }

        $expected = self::ACCOUNT_TYPES[$group][$key] ?? null;
        if ($expected === null) {
            return "{$group}.{$key} is not a known account mapping.";
        }

        $code = trim((string) $value);
        $account = $code === '' ? null : Account::query()->where('code', $code)->first();

        if (! $account) {
            return "{$group}.{$key}: account '{$code}' does not exist.";
        }
        if (! $account->is_active) {
            return "{$group}.{$key}: account '{$code}' is inactive.";
        }
        if (! $account->is_postable) {
            return "{$group}.{$key}: account '{$code}' is not postable.";
        }
        if ($account->type !== $expected) {
            return "{$group}.{$key}: account '{$code}' must be of type {$expected}, found {$account->type}.";
        }

        return null;
    }

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
