<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Setting;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        // MCP gateway (ADR-0016). Off by default: while 'enabled' is false no MCP token can be
        // created and every request made with one is refused (403 mcp_disabled).
        'mcp' => [
            'enabled' => 'false',
            // Contact details (phone, e-mail, tax id) are masked in MCP token responses.
            'mask_contacts' => 'true',
            // 'daily_row_budget' (rows one MCP token may read per day, blueprint section 8) has NO built-in
            // setting default on purpose: its default is config('mcp.daily_row_budget') (env MCP_DAILY_ROW_BUDGET),
            // and a stored setting overrides it.
        ],
        'general' => [
            'company_name_ar' => 'نظام إدارة السياحة',
            'company_name_en' => 'Tourism ERP',
            // Letterhead logo for PDF reports: a PNG/JPEG path relative to storage/app, or a data URI. Empty = no logo.
            'company_logo' => '',
            // Print "Prepared by / Approved by / Date" lines at the end of PDF reports.
            'report_signoff' => 'true',
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
            // Inter-branch clearing (Odoo-style due-from / due-to). Used by fixed-asset
            // transfers between branches; they net to zero installation-wide.
            'interbranch_receivable_account_code' => '1180',
            'interbranch_payable_account_code' => '2160',
            // PENDING ACCOUNTANT APPROVAL: supplier advances (AC-PAY-009) are
            // an asset until applied to an obligation. Configurable per install.
            'supplier_advance_account_code' => '1153',
            'tax_payable_account_code' => '2200',
            'fx_gain_account_code' => '7900',
            'fx_loss_account_code' => '6900',
            // Unrealized FX (period-end revaluation, DEC-008); existing installs
            // create these with `php artisan accounting:ensure-fx-accounts`.
            'fx_unrealized_gain_account_code' => '7901',
            'fx_unrealized_loss_account_code' => '6901',
            // PENDING ACCOUNTANT APPROVAL (DEC-031/032/034): provisional account mappings.
            'bank_charges_account_code' => '6500',
            // PENDING ACCOUNTANT APPROVAL (DEC-037): interest earned credited on reconciliation.
            'bank_interest_account_code' => '7920',
            'input_tax_account_code' => '1160',
            // PENDING ACCOUNTANT APPROVAL: output tax collected on customer sales.
            'output_tax_account_code' => '2200',
            'retained_earnings_account_code' => '3100',
            // PENDING ACCOUNTANT APPROVAL (DEC-035): employee advances/custody accounts.
            'employee_advance_account_code' => '1151',
            'employee_custody_account_code' => '1152',
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
            'prepaid_expense_account_code' => '1154',
            'deferred_revenue_account_code' => '2400',
            'accrued_liability_account_code' => '2100',
            'accrued_revenue_account_code' => '1100',
            // PENDING ACCOUNTANT APPROVAL (DEC-004): infrastructure only;
            // no commission journal is posted while auto-post remains false.
            // Optional, like Odoo/Zoho approval rules: when 'true' the requester of a refund cannot approve it.
            'refund_segregation' => 'false',
            'commission_trigger' => 'fully_collected',
            'commission_auto_post' => 'false',
            // Commission expense (DEC-003: expense + payable). The payable is the
            // beneficiary's party subaccount under the payables control account
            // (ADR-0015). Existing installs create 6700 with accounting:ensure-accounts.
            'commission_expense_account_code' => '6700',
            // PENDING ACCOUNTANT APPROVAL (DEC-008): revaluation posting is
            // disabled until accounts, eligibility and reversal policy sign-off.
            'fx_revaluation_enabled' => 'false',
            'fx_revaluation_auto_reverse' => 'true',
            // PENDING ACCOUNTANT APPROVAL (DEC-025): manager approval is the
            // guarded product policy; zero margin warns but does not auto-post.
            'negative_margin_requires_approval' => 'true',
            'zero_margin_policy' => 'warn',
            // PENDING ACCOUNTANT APPROVAL: customer credit limit enforcement.
            // off = ignore, warn = allow + audit + warning, block = refuse
            // unless a permitted user overrides with a reason.
            'credit_limit_policy' => 'warn',
        ],
        'treasury' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-031): refuse transfers that overdraw a cash/bank account.
            'block_negative_cash' => 'false',
            // DEC-017 (work order 02): the person who prepared a bank
            // reconciliation cannot approve it (preparer vs reviewer).
            'reconciliation_segregation' => 'true',
            // PENDING ACCOUNTANT APPROVAL (DEC-037): bank reconciliation matching rules.
            'reconciliation_tolerance_minor' => '0',
            'reconciliation_date_window_days' => '5',
            // Ledger lines posted up to this many days AFTER the period end may be matched to a bank
            // line of the period (Odoo matches regardless of period). Matching only: they never
            // change the period's book balance. 0 disables the look-ahead; maximum 366.
            'reconciliation_lookahead_days' => '31',
            'reconciliation_auto_match' => 'false',
        ],
        'expenses' => [
            // PENDING ACCOUNTANT APPROVAL (DEC-032): the creator may not approve their own expense.
            'segregation' => 'true',
        ],
        'invoices' => [
            // DEC-017 (work order 02): the person who created or submitted an
            // invoice cannot approve it, matching the expense and voucher
            // rules. Odoo/Zoho approvals are per document type; this
            // installation keeps the control on by default.
            'segregation' => 'true',
        ],
        'commissions' => [
            // The person who created or submitted a rule, or overrode a
            // commission amount, cannot approve it (maker-checker, as for expenses).
            'segregation' => 'true',
        ],
        'employees' => [
            // DEC-017 (work order 02): the person who requested an employee
            // advance cannot approve it (money leaves the cash account on
            // disbursement).
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
            'inclusive' => 'false',
        ],
        'crm' => [
            'reassign_after_hours' => '24',
            // A new capture matching an existing lead by normalized phone/email
            // inside this many days is flagged and linked instead of silently
            // duplicated. 0 disables duplicate detection (GAP-CRM-04).
            'duplicate_window_days' => '30',
            'assignment_strategy' => 'least_loaded',
            'business_timezone' => 'Africa/Cairo',
            'business_days' => '1,2,3,4,5',
            'business_day_start' => '09:00',
            'business_day_end' => '17:00',
            'reassignment_max_attempts' => '3',
            'meaningful_activity_types' => 'note,call,email,whatsapp,stage_changed',
        ],
        // Optional Meta Lead Ads / WhatsApp Cloud API integrations (ADR-0010).
        // Everything is OFF by default. Non-secret ids only: app secrets,
        // verify tokens and access tokens come from env (config/services.php)
        // and are never stored here.
        'integrations' => [
            'meta_leads_enabled' => 'false',
            'meta_page_id' => '',
            'meta_default_branch_id' => '',
            // JSON object {"<form_id>": "<branch public id>"}; optional.
            'meta_form_branch_map' => '',
            // UNVERIFIED: confirm the current supported Graph API version.
            'meta_graph_version' => 'v21.0',
            'meta_webhook_verified_at' => '',
            'whatsapp_enabled' => 'false',
            'whatsapp_outbound_enabled' => 'false',
            'whatsapp_phone_number_id' => '',
            'whatsapp_business_account_id' => '',
            'whatsapp_graph_version' => 'v21.0',
            'whatsapp_webhook_verified_at' => '',
            'whatsapp_stop_keywords' => 'stop,unsubscribe,إيقاف,الغاء',
        ],
        'documents' => [
            'default_language' => 'bilingual',
            // DEC-017 (work order 02): the person who submitted a voucher
            // cannot approve it. Odoo/Zoho ship approval rules per document
            // type; this installation keeps the control on by default.
            'segregation' => 'true',
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
            'commission_prefix' => 'CM',
            'commission_payment_prefix' => 'CP',
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
            'interbranch_receivable_account_code' => 'asset',
            'interbranch_payable_account_code' => 'liability',
            'advance_account_code' => 'liability',
            'supplier_advance_account_code' => 'asset',
            'revenue_account_code' => 'revenue',
            'cost_account_code' => 'expense',
            'commission_expense_account_code' => 'expense',
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
    public const POLICY_KEYS = ['accounting_policy_approved', 'auto_post_bookings', 'commission_auto_post'];

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

    /**
     * Settings keys (`group.key`) whose account mapping currently points at
     * this account code. Used to protect stored mappings from account edits.
     *
     * @return list<string>
     */
    public function mappedSettingKeysForAccountCode(string $code): array
    {
        $keys = [];
        foreach (self::ACCOUNT_TYPES as $group => $mappings) {
            foreach (array_keys($mappings) as $key) {
                if ((string) $this->get($group, $key) === $code) {
                    $keys[] = "{$group}.{$key}";
                }
            }
        }

        return $keys;
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

    /**
     * The base currency is fixed once any journal entry has been posted:
     * converted base amounts of posted entries must never be reinterpreted.
     */
    public function baseCurrencyLocked(): bool
    {
        return DB::table('journal_entries')->where('status', 'posted')->exists();
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

        $result[] = [
            'group' => 'general',
            'key' => 'base_currency_locked',
            'value' => $this->baseCurrencyLocked() ? 'true' : 'false',
            'is_secret' => false,
        ];

        return $result;
    }

    public function set(string $group, string $key, ?string $value, bool $secret = false): Setting
    {
        if ($group === 'general' && $key === 'base_currency_locked') {
            throw new DomainException('base_currency_locked is computed and cannot be written.');
        }

        if ($group === 'general' && $key === 'base_currency'
            && strtoupper((string) $value) !== $this->baseCurrency()
            && $this->baseCurrencyLocked()) {
            throw new DomainException('The base currency cannot be changed after the first journal entry has been posted. / لا يمكن تغيير العملة الأساسية بعد ترحيل أول قيد يومية.');
        }

        Cache::forget("settings.{$group}.{$key}");
        if ($group === 'accounting' && $key === 'rounding_policy') {
            app(RoundingPolicy::class)->flush();
        }

        return Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value, 'is_secret' => $secret],
        );
    }
}
