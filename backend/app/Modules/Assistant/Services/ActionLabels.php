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
        'erp_invoice_submit' => ['ar' => 'إرسال فاتورة للاعتماد', 'en' => 'Send an invoice for approval'],
        'erp_invoice_approve' => ['ar' => 'اعتماد فاتورة', 'en' => 'Approve an invoice'],
        'erp_invoice_issue' => ['ar' => 'إصدار فاتورة', 'en' => 'Issue an invoice'],
        'erp_invoice_void' => ['ar' => 'إلغاء فاتورة', 'en' => 'Void an invoice'],
        'erp_expense_submit' => ['ar' => 'إرسال مصروف للاعتماد', 'en' => 'Send an expense for approval'],
        'erp_expense_approve' => ['ar' => 'اعتماد مصروف', 'en' => 'Approve an expense'],
        'erp_document_submit' => ['ar' => 'إرسال سند للاعتماد', 'en' => 'Send a voucher for approval'],
        'erp_document_approve' => ['ar' => 'اعتماد سند', 'en' => 'Approve a voucher'],
        'erp_document_post' => ['ar' => 'ترحيل سند', 'en' => 'Post a voucher'],
        'erp_commission_approve' => ['ar' => 'اعتماد عمولة', 'en' => 'Approve a commission'],
        'erp_commission_post' => ['ar' => 'ترحيل عمولة', 'en' => 'Post a commission'],
        'erp_commission_pay' => ['ar' => 'صرف عمولة', 'en' => 'Pay a commission'],
        'erp_refund_approve' => ['ar' => 'اعتماد استرداد', 'en' => 'Approve a refund'],
        'erp_journal_post' => ['ar' => 'ترحيل قيد يومية', 'en' => 'Post a journal entry'],
        'erp_journal_draft_delete' => ['ar' => 'حذف مسودة قيد', 'en' => 'Delete a draft journal entry'],
        'erp_payment_record' => ['ar' => 'تسجيل تحصيل من عميل', 'en' => 'Record a customer payment'],
        'erp_supplier_payment_record' => ['ar' => 'صرف دفعة لمورد', 'en' => 'Pay a supplier'],
        'erp_export_queue' => ['ar' => 'تصدير تقرير', 'en' => 'Export a report'],
    ];

    /** What confirming does, in one sentence, shown on the card of every action on a record or on money. */
    private const EFFECTS = [
        'erp_invoice_submit' => ['ar' => 'ستنتقل الفاتورة من «مسودة» إلى «قيد المراجعة» وتنتظر معتمداً.', 'en' => 'The invoice moves from Draft to Submitted and waits for an approver.'],
        'erp_invoice_approve' => ['ar' => 'ستُعتمد الفاتورة ولا تُصدر بعد.', 'en' => 'The invoice is approved but not yet issued.'],
        'erp_invoice_issue' => ['ar' => 'ستُصدر الفاتورة وتُرحَّل إلى القيود المحاسبية.', 'en' => 'The invoice is issued and posted to the ledger.'],
        'erp_invoice_void' => ['ar' => 'ستُلغى الفاتورة، وتُعكس آثارها المحاسبية إن كانت مُرحَّلة.', 'en' => 'The invoice is voided, and its accounting effect is reversed if it was posted.'],
        'erp_expense_submit' => ['ar' => 'سينتقل المصروف من «مسودة» إلى «قيد المراجعة».', 'en' => 'The expense moves from Draft to Submitted.'],
        'erp_expense_approve' => ['ar' => 'سيُعتمد المصروف وفق سياسة النظام، وقد يُرحَّل إلى القيود المحاسبية.', 'en' => 'The expense is approved under the system policy and may be posted to the ledger.'],
        'erp_document_submit' => ['ar' => 'سينتقل السند إلى «قيد المراجعة».', 'en' => 'The voucher moves to Submitted.'],
        'erp_document_approve' => ['ar' => 'سيُعتمد السند ولا يُرحَّل بعد.', 'en' => 'The voucher is approved but not yet posted.'],
        'erp_document_post' => ['ar' => 'سيُرحَّل السند إلى القيود المحاسبية، ولا يمكن تعديله بعد ذلك.', 'en' => 'The voucher is posted to the ledger and can no longer be edited.'],
        'erp_commission_approve' => ['ar' => 'ستُعتمد العمولة ولا تُرحَّل ولا تُصرف بعد.', 'en' => 'The commission is approved, not yet posted or paid.'],
        'erp_commission_post' => ['ar' => 'ستُرحَّل العمولة إلى القيود المحاسبية.', 'en' => 'The commission is posted to the ledger.'],
        'erp_commission_pay' => ['ar' => 'ستُصرف العمولة من الحساب المحدد وتُسجَّل في القيود.', 'en' => 'The commission is paid from the chosen account and recorded in the ledger.'],
        'erp_refund_approve' => ['ar' => 'سيُعتمد الاسترداد ويُسجَّل أثره المالي.', 'en' => 'The refund is approved and its financial effect is recorded.'],
        'erp_journal_post' => ['ar' => 'سيُرحَّل القيد ولا يمكن تعديله بعد ذلك، ويمكن عكسه فقط.', 'en' => 'The entry is posted and can no longer be edited, only reversed.'],
        'erp_journal_draft_delete' => ['ar' => 'ستُحذف المسودة نهائياً ولا يمكن استرجاعها.', 'en' => 'The draft is deleted for good and cannot be recovered.'],
        'erp_payment_record' => ['ar' => 'سيُسجَّل التحصيل في الحساب المحدد ويُرحَّل إلى القيود المحاسبية.', 'en' => 'The payment is recorded in the chosen account and posted to the ledger.'],
        'erp_supplier_payment_record' => ['ar' => 'ستُصرف الدفعة من الحساب المحدد وتُرحَّل إلى القيود المحاسبية.', 'en' => 'The payment is made from the chosen account and posted to the ledger.'],
        'erp_export_queue' => ['ar' => 'سيُجهَّز ملف التقرير في الخلفية ويظهر في قائمة التصدير.', 'en' => 'The report file is prepared in the background and appears in the export list.'],
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
        '__lead' => ['ar' => 'العميل المحتمل', 'en' => 'Lead'], '__customer' => ['ar' => 'العميل', 'en' => 'Customer'], '__category' => ['ar' => 'فئة المصروف', 'en' => 'Expense category'], '__payee' => ['ar' => 'المورد', 'en' => 'Supplier'], '__booking' => ['ar' => 'الحجز المرتبط', 'en' => 'Linked booking'], '__payment' => ['ar' => 'حساب الدفع', 'en' => 'Payment account'],
        '__record' => ['ar' => 'السجل', 'en' => 'Record'], '__status' => ['ar' => 'حالته الآن', 'en' => 'Current status'], '__total' => ['ar' => 'إجمالي المبلغ', 'en' => 'Total amount'], '__paid' => ['ar' => 'المصروف سابقاً', 'en' => 'Already paid'], '__note' => ['ar' => 'البيان', 'en' => 'Note'],
        'report' => ['ar' => 'التقرير', 'en' => 'Report'], 'format' => ['ar' => 'الصيغة', 'en' => 'Format'], 'language' => ['ar' => 'اللغة', 'en' => 'Language'], 'method' => ['ar' => 'طريقة الدفع', 'en' => 'Method'],
        'paid_at' => ['ar' => 'تاريخ الدفع', 'en' => 'Paid on'], 'params' => ['ar' => 'عوامل التصفية', 'en' => 'Filters'],
        'amount_minor' => ['ar' => 'المبلغ', 'en' => 'Amount'], 'lines' => ['ar' => 'البنود', 'en' => 'Lines'], 'items' => ['ar' => 'البنود', 'en' => 'Items'],
    ];

    private const SERVICES = [
        'flight' => ['ar' => 'طيران', 'en' => 'Flight'], 'hotel' => ['ar' => 'فندق', 'en' => 'Hotel'], 'transport' => ['ar' => 'نقل', 'en' => 'Transport'],
        'visa' => ['ar' => 'تأشيرة', 'en' => 'Visa'], 'program' => ['ar' => 'برنامج', 'en' => 'Program'], 'other' => ['ar' => 'أخرى', 'en' => 'Other'],
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
        foreach (['__record', '__status', '__total', '__paid', '__note', '__lead', '__customer', '__booking', '__category', '__payee', '__payment'] as $key) {
            if (isset($payload[$key])) {
                $rows[] = ['label' => self::FIELDS[$key][$l], 'value' => (string) $payload[$key]];
            }
        }
        foreach ($payload as $key => $value) {
            if (in_array($key, self::HIDDEN, true) || str_starts_with((string) $key, '__') || $value === null || $value === '' || $key === 'lines' || $key === 'items') {
                continue;
            }
            $label = self::FIELDS[$key][$l] ?? $key;
            if (is_array($value)) {
                $value = collect($value)->map(fn ($v, $k) => $k.' = '.$v)->implode('، ');
            }
            $rows[] = ['label' => $label, 'value' => $key === 'amount_minor' ? self::money((int) $value, $currency) : (string) (is_bool($value) ? ($value ? 'yes' : 'no') : $value)];
        }
        if (isset($payload['__allocation'])) {
            $rows[] = ['label' => $l === 'ar' ? 'التخصيص' : 'Allocation', 'value' => $payload['__allocation'] === 'booking'
                ? ($l === 'ar' ? 'سيُخصَّص كامل المبلغ على الحجز '.($payload['__booking'] ?? '') : 'The whole amount is allocated to booking '.($payload['__booking'] ?? ''))
                : ($l === 'ar' ? 'دفعة مقدمة غير مخصصة لأي حجز أو فاتورة' : 'An unallocated advance, not tied to any booking or invoice')];
        }
        if (isset($payload['__tool'], self::EFFECTS[$payload['__tool']])) {
            $rows[] = ['label' => $l === 'ar' ? 'ما سيحدث' : 'What happens', 'value' => self::EFFECTS[$payload['__tool']][$l]];
        }
        foreach (['lines', 'items'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $index => $line) {
                $rows[] = ['label' => self::FIELDS[$key][$l].' '.($index + 1), 'value' => self::line($line, $currency, $l)];
            }
        }

        return $rows;
    }

    /** Every value of an invoice line or booking item that reaches the route, so the card is the exact payload. */
    private static function line(array $line, string $currency, string $l): string
    {
        $type = isset($line['service_type']) ? '['.(self::SERVICES[$line['service_type']][$l] ?? $line['service_type']).'] ' : '';
        $text = $type.trim(($line['description'] ?? '').' × '.($line['quantity'] ?? 1));
        $parts = [];
        if (isset($line['unit_cost_minor'])) {
            $parts[] = ($l === 'ar' ? 'التكلفة ' : 'cost ').self::money((int) $line['unit_cost_minor'], $currency);
        }
        $price = $line['unit_sell_minor'] ?? $line['unit_price_minor'] ?? null;
        if ($price !== null) {
            $parts[] = ($l === 'ar' ? 'سعر البيع ' : 'sell ').self::money((int) $price, $currency);
        }
        if (isset($line['supplier_id'])) {
            $parts[] = ($l === 'ar' ? 'المورد ' : 'supplier ').($line['__supplier'] ?? $line['supplier_id']);
        }

        return $parts === [] ? $text : $text.' — '.implode(' · ', $parts);
    }

    public static function moneyText(int $minor, string $currency): string
    {
        return self::money($minor, $currency);
    }

    private static function money(int $minor, string $currency): string
    {
        $exponent = $currency !== '' ? (int) (Currency::query()->where('code', $currency)->value('exponent') ?? 2) : 2;
        $value = number_format($minor / (10 ** $exponent), $exponent, '.', ',');

        return trim($value.' '.$currency);
    }
}
