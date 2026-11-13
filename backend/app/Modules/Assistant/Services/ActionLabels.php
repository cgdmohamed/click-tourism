<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Money\Models\Currency;

/** Localised titles and the rows of a confirmation card (what the user is asked to approve). */
final class ActionLabels
{
    private const TITLES = [
        'erp_lead_create' => ['ar' => 'إنشاء عميل محتمل', 'en' => 'Create a lead'],
        'erp_lead_update' => ['ar' => 'تحديث عميل محتمل', 'en' => 'Update a lead'],
        'erp_customer_create' => ['ar' => 'إنشاء عميل', 'en' => 'Create a customer'],
        'erp_supplier_create' => ['ar' => 'إنشاء مورّد', 'en' => 'Create a supplier'],
        'erp_invoice_draft_create' => ['ar' => 'إنشاء مسودة فاتورة عميل', 'en' => 'Create a customer invoice draft'],
        'erp_expense_draft_create' => ['ar' => 'إنشاء مسودة مصروف', 'en' => 'Create an expense draft'],
        'erp_booking_draft_create' => ['ar' => 'إنشاء مسودة حجز', 'en' => 'Create a booking draft'],
    ];

    private const FIELDS = [
        'name' => ['ar' => 'الاسم', 'en' => 'Name'], 'name_ar' => ['ar' => 'الاسم بالعربية', 'en' => 'Arabic name'], 'name_en' => ['ar' => 'الاسم بالإنجليزية', 'en' => 'English name'],
        'phone' => ['ar' => 'الهاتف', 'en' => 'Phone'], 'email' => ['ar' => 'البريد', 'en' => 'Email'], 'tax_id' => ['ar' => 'الرقم الضريبي', 'en' => 'Tax id'],
        'source' => ['ar' => 'المصدر', 'en' => 'Source'], 'notes' => ['ar' => 'ملاحظات', 'en' => 'Notes'], 'note' => ['ar' => 'ملاحظة', 'en' => 'Note'],
        'service_category' => ['ar' => 'نوع الخدمة', 'en' => 'Service'], 'stage' => ['ar' => 'المرحلة', 'en' => 'Stage'], 'type' => ['ar' => 'النوع', 'en' => 'Type'],
        'category' => ['ar' => 'الفئة', 'en' => 'Category'], 'currency' => ['ar' => 'العملة', 'en' => 'Currency'], 'settlement_currency' => ['ar' => 'عملة التسوية', 'en' => 'Settlement currency'],
        'issue_date' => ['ar' => 'تاريخ الإصدار', 'en' => 'Issue date'], 'due_date' => ['ar' => 'تاريخ الاستحقاق', 'en' => 'Due date'], 'memo' => ['ar' => 'بيان', 'en' => 'Memo'],
        'expense_date' => ['ar' => 'تاريخ المصروف', 'en' => 'Expense date'], 'description' => ['ar' => 'الوصف', 'en' => 'Description'], 'reference' => ['ar' => 'المرجع', 'en' => 'Reference'],
        'payee_name' => ['ar' => 'المستفيد', 'en' => 'Payee'], 'start_date' => ['ar' => 'تاريخ البدء', 'en' => 'Start date'], 'end_date' => ['ar' => 'تاريخ الانتهاء', 'en' => 'End date'],
        'pax_adults' => ['ar' => 'عدد البالغين', 'en' => 'Adults'], 'pax_children' => ['ar' => 'عدد الأطفال', 'en' => 'Children'],
        '__lead' => ['ar' => 'العميل المحتمل', 'en' => 'Lead'], '__customer' => ['ar' => 'العميل', 'en' => 'Customer'], '__category' => ['ar' => 'فئة المصروف', 'en' => 'Expense category'], '__payee' => ['ar' => 'المورد', 'en' => 'Supplier'], '__payment' => ['ar' => 'حساب الدفع', 'en' => 'Payment account'],
        'amount_minor' => ['ar' => 'المبلغ', 'en' => 'Amount'], 'lines' => ['ar' => 'البنود', 'en' => 'Lines'], 'items' => ['ar' => 'البنود', 'en' => 'Items'],
    ];

    private const HIDDEN = ['branch_id', 'party_type', 'party_id', 'category_id', 'customer_id', 'booking_id', 'submit', 'payee_type', 'payee_id', 'cash_account_id', '__id'];

    public static function title(string $tool, string $locale): string
    {
        return (self::TITLES[$tool] ?? ['ar' => $tool, 'en' => $tool])[$locale === 'en' ? 'en' : 'ar'];
    }

    /** @return list<array{label: string, value: string}> */
    public static function rows(array $payload, string $locale): array
    {
        $l = $locale === 'en' ? 'en' : 'ar';
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $rows = [];
        foreach (['__lead', '__customer', '__category', '__payee', '__payment'] as $key) {
            if (isset($payload[$key])) {
                $rows[] = ['label' => self::FIELDS[$key][$l], 'value' => (string) $payload[$key]];
            }
        }
        foreach ($payload as $key => $value) {
            if (in_array($key, self::HIDDEN, true) || str_starts_with((string) $key, '__') || $value === null || $value === '' || $key === 'lines' || $key === 'items') {
                continue;
            }
            $label = self::FIELDS[$key][$l] ?? $key;
            $rows[] = ['label' => $label, 'value' => $key === 'amount_minor' ? self::money((int) $value, $currency) : (string) (is_bool($value) ? ($value ? 'yes' : 'no') : $value)];
        }
        foreach (['lines', 'items'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $index => $line) {
                $price = $line['unit_price_minor'] ?? $line['unit_sell_minor'] ?? null;
                $text = trim(($line['description'] ?? '').' × '.($line['quantity'] ?? 1));
                if ($price !== null) {
                    $text .= ' — '.self::money((int) $price, $currency);
                }
                $rows[] = ['label' => self::FIELDS[$key][$l].' '.($index + 1), 'value' => $text];
            }
        }

        return $rows;
    }

    private static function money(int $minor, string $currency): string
    {
        $exponent = $currency !== '' ? (int) (Currency::query()->where('code', $currency)->value('exponent') ?? 2) : 2;
        $value = number_format($minor / (10 ** $exponent), $exponent, '.', ',');

        return trim($value.' '.$currency);
    }
}
