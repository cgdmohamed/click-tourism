<?php

namespace App\Modules\Core\Services;

/**
 * Registry of product/accounting decisions that are not yet approved.
 *
 * This file must be kept in sync with docs/product/decisions-needed.md.
 * Each entry mirrors one row of that document: the id, the type, a short
 * title in both languages, the slices it blocks, and its approval status.
 *
 * Nothing is silently assumed: unresolved entries are surfaced in the UI and
 * in the health endpoint until an owner marks them "decided".
 */
class DecisionRegistry
{
    /** @var array<int, array{id: string, type: string, title_en: string, title_ar: string, blocks: string, status: string}> */
    private const DECISIONS = [
        [
            'id' => 'DEC-001',
            'type' => 'ACC',
            'title_en' => 'Revenue recognition point for bookings',
            'title_ar' => 'نقطة الاعتراف بالإيراد للحجوزات',
            'blocks' => 'FIN-009, BOOK, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-002',
            'type' => 'ACC',
            'title_en' => 'Supplier cost recognition',
            'title_ar' => 'الاعتراف بتكلفة المورد',
            'blocks' => 'FIN-009, PAY, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-003',
            'type' => 'ACC',
            'title_en' => 'Commission accounting treatment',
            'title_ar' => 'معالجة العمولات محاسبيًا',
            'blocks' => 'COMM, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-004',
            'type' => 'ACC',
            'title_en' => 'Commission accrual trigger',
            'title_ar' => 'مُحفّز استحقاق العمولة',
            'blocks' => 'COMM',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-005',
            'type' => 'ACC',
            'title_en' => 'Customer advances',
            'title_ar' => 'دفعات العملاء المقدمة',
            'blocks' => 'PAY, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-006',
            'type' => 'ACC',
            'title_en' => 'Cancellation fee treatment',
            'title_ar' => 'معالجة رسوم الإلغاء',
            'blocks' => 'BOOK, PAY, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-007',
            'type' => 'ACC',
            'title_en' => 'FX policy',
            'title_ar' => 'سياسة العملات الأجنبية',
            'blocks' => 'FIN, PAY, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-008',
            'type' => 'ACC',
            'title_en' => 'Period-end FX revaluation',
            'title_ar' => 'إعادة تقييم العملات آخر الفترة',
            'blocks' => 'FIN-010, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-009',
            'type' => 'ACC',
            'title_en' => 'Tax point and inclusive/exclusive defaults',
            'title_ar' => 'نقطة الضريبة والاحتساب الشامل',
            'blocks' => 'TAX, BOOK, RPT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-010',
            'type' => 'ACC',
            'title_en' => 'Rounding and residual account',
            'title_ar' => 'التقريب والحساب المتبقي',
            'blocks' => 'FIN, PAY, TAX',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-011',
            'type' => 'ACC',
            'title_en' => 'Opening balances method and cutover date',
            'title_ar' => 'طريقة الأرصدة الافتتاحية وتاريخ التحويل',
            'blocks' => 'FIN-005, DATA',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-012',
            'type' => 'ACC',
            'title_en' => 'Fiscal year start and closing procedure',
            'title_ar' => 'بداية السنة المالية وإجراء الإقفال',
            'blocks' => 'FIN-002, FIN-010',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-013',
            'type' => 'ACC',
            'title_en' => 'Commission rule precedence',
            'title_ar' => 'أولوية قواعد العمولة',
            'blocks' => 'COMM',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-014',
            'type' => 'ACC',
            'title_en' => 'Branch accounting model',
            'title_ar' => 'نموذج محاسبة الفروع',
            'blocks' => 'FIN, RPT, AUTH',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-015',
            'type' => 'HUM',
            'title_en' => '24h reassignment definition',
            'title_ar' => 'تعريف إعادة التوزيع خلال 24 ساعة',
            'blocks' => 'CRM-005',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-016',
            'type' => 'HUM',
            'title_en' => 'Lead assignment strategy',
            'title_ar' => 'استراتيجية توزيع العملاء المحتملين',
            'blocks' => 'CRM-004',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-017',
            'type' => 'ACC',
            'title_en' => 'Approval thresholds and segregation of duties',
            'title_ar' => 'حدود الموافقة وفصل المهام',
            'blocks' => 'FIN, PAY, COMM, AUTH',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-018',
            'type' => 'ARC',
            'title_en' => 'Document numbering',
            'title_ar' => 'ترقيم المستندات',
            'blocks' => 'CORE-007',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-019',
            'type' => 'ACC',
            'title_en' => 'Deletion and amendment policy',
            'title_ar' => 'سياسة الحذف والتعديل',
            'blocks' => 'ALL',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-020',
            'type' => 'HUM',
            'title_en' => 'Multi-company support',
            'title_ar' => 'دعم تعدد الشركات',
            'blocks' => 'architecture',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-021',
            'type' => 'ACC',
            'title_en' => 'Tax on commissions and withholding',
            'title_ar' => 'الضريبة على العمولات والاستقطاع',
            'blocks' => 'COMM, TAX',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-022',
            'type' => 'HUM',
            'title_en' => 'Customer and supplier portal',
            'title_ar' => 'بوابة العملاء والموردين',
            'blocks' => 'scope',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-023',
            'type' => 'HUM',
            'title_en' => 'Payment methods and cash reconciliation',
            'title_ar' => 'طرق الدفع ومطابقة النقدية',
            'blocks' => 'PAY',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-024',
            'type' => 'HUM',
            'title_en' => 'Data retention and PII',
            'title_ar' => 'الاحتفاظ بالبيانات وخصوصية البيانات',
            'blocks' => 'CORE-006, CRM, INT',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-025',
            'type' => 'ACC',
            'title_en' => 'Negative-margin and zero-value bookings',
            'title_ar' => 'الحجوزات بالسالب أو بدون قيمة',
            'blocks' => 'BOOK, COMM',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-026',
            'type' => 'HUM',
            'title_en' => 'Notification channels',
            'title_ar' => 'قنوات الإشعارات',
            'blocks' => 'CORE, CRM',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-027',
            'type' => 'ARC',
            'title_en' => 'Idempotency scope',
            'title_ar' => 'نطاق منع التكرار',
            'blocks' => 'ALL',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-028',
            'type' => 'ACC',
            'title_en' => 'Booking invoice document',
            'title_ar' => 'مستند فاتورة الحجز',
            'blocks' => 'BOOK, RPT, TAX',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-029',
            'type' => 'HUM',
            'title_en' => 'Language of legal documents',
            'title_ar' => 'لغة المستندات القانونية',
            'blocks' => 'RPT, UI',
            'status' => 'open',
        ],
        [
            'id' => 'DEC-030',
            'type' => 'HUM',
            'title_en' => 'Reference data seeding',
            'title_ar' => 'تهيئة البيانات المرجعية',
            'blocks' => 'FOUNDATION',
            'status' => 'open',
        ],
    ];

    /** @return array<int, array{id: string, type: string, title_en: string, title_ar: string, blocks: string, status: string}> */
    public function all(): array
    {
        return self::DECISIONS;
    }

    /** @return array<int, array{id: string, type: string, title_en: string, title_ar: string, blocks: string, status: string}> */
    public function unresolved(): array
    {
        return array_values(array_filter(
            self::DECISIONS,
            static fn (array $decision): bool => $decision['status'] !== 'decided',
        ));
    }
}
