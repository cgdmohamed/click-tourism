<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Setting;

/**
 * Registry of product/accounting decisions.
 *
 * 2026-11-11: the project owner closed every open decision by adopting the
 * behaviour of the reference systems (Odoo, Zoho Books); the chosen rule for
 * each decision is in docs/product/decisions-closed-2026-11-11.md. The
 * entries stay in the registry as an audit trail; `unresolved()` is empty
 * unless a new decision is added.
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
    public const SETTINGS_GROUP = 'decisions';

    /** @var array<int, array{id: string, type: string, title_en: string, title_ar: string, blocks: string, status: string}> */
    private const DECISIONS = [
        [
            'id' => 'DEC-001',
            'type' => 'ACC',
            'title_en' => 'Revenue recognition point for bookings',
            'title_ar' => 'نقطة الاعتراف بالإيراد للحجوزات',
            'blocks' => 'FIN-009, BOOK, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-002',
            'type' => 'ACC',
            'title_en' => 'Supplier cost recognition',
            'title_ar' => 'الاعتراف بتكلفة المورد',
            'blocks' => 'FIN-009, PAY, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-003',
            'type' => 'ACC',
            'title_en' => 'Commission accounting treatment',
            'title_ar' => 'معالجة العمولات محاسبيًا',
            'blocks' => 'COMM, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-004',
            'type' => 'ACC',
            'title_en' => 'Commission accrual trigger',
            'title_ar' => 'مُحفّز استحقاق العمولة',
            'blocks' => 'COMM',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-005',
            'type' => 'ACC',
            'title_en' => 'Customer advances',
            'title_ar' => 'دفعات العملاء المقدمة',
            'blocks' => 'PAY, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-006',
            'type' => 'ACC',
            'title_en' => 'Cancellation fee treatment',
            'title_ar' => 'معالجة رسوم الإلغاء',
            'blocks' => 'BOOK, PAY, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-007',
            'type' => 'ACC',
            'title_en' => 'FX policy',
            'title_ar' => 'سياسة العملات الأجنبية',
            'blocks' => 'FIN, PAY, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-008',
            'type' => 'ACC',
            'title_en' => 'Period-end FX revaluation of open AR/AP',
            'title_ar' => 'إعادة تقييم العملات آخر الفترة',
            'blocks' => 'FIN-010, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-009',
            'type' => 'ACC',
            'title_en' => 'Tax point and inclusive/exclusive defaults',
            'title_ar' => 'نقطة الضريبة والاحتساب الشامل',
            'blocks' => 'TAX, BOOK, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-010',
            'type' => 'ACC',
            'title_en' => 'Rounding and residual account',
            'title_ar' => 'التقريب والحساب المتبقي',
            'blocks' => 'FIN, PAY, TAX',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-011',
            'type' => 'ACC',
            'title_en' => 'Opening balances method and cutover date',
            'title_ar' => 'طريقة الأرصدة الافتتاحية وتاريخ التحويل',
            'blocks' => 'FIN-005, DATA',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-012',
            'type' => 'ACC',
            'title_en' => 'Fiscal year start and closing procedure',
            'title_ar' => 'بداية السنة المالية وإجراء الإقفال',
            'blocks' => 'FIN-002, FIN-010',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-013',
            'type' => 'ACC/HUM',
            'title_en' => 'Commission rule precedence',
            'title_ar' => 'أولوية قواعد العمولة',
            'blocks' => 'COMM',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-014',
            'type' => 'ACC',
            'title_en' => 'Branch accounting model',
            'title_ar' => 'نموذج محاسبة الفروع',
            'blocks' => 'FIN, RPT, AUTH',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-015',
            'type' => 'HUM',
            'title_en' => '24h reassignment definition',
            'title_ar' => 'تعريف إعادة التوزيع خلال 24 ساعة',
            'blocks' => 'CRM-005',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-016',
            'type' => 'HUM',
            'title_en' => 'Lead assignment strategy',
            'title_ar' => 'استراتيجية توزيع العملاء المحتملين',
            'blocks' => 'CRM-004',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-017',
            'type' => 'ACC/HUM',
            'title_en' => 'Approval thresholds and segregation of duties',
            'title_ar' => 'حدود الموافقة وفصل المهام',
            'blocks' => 'FIN, PAY, COMM, AUTH',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-018',
            'type' => 'ARC/HUM',
            'title_en' => 'Document numbering',
            'title_ar' => 'ترقيم المستندات',
            'blocks' => 'CORE-007',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-019',
            'type' => 'ACC',
            'title_en' => 'Deletion / amendment policy',
            'title_ar' => 'سياسة الحذف والتعديل',
            'blocks' => 'ALL',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-020',
            'type' => 'HUM/ARC',
            'title_en' => 'Multi-company support',
            'title_ar' => 'دعم تعدد الشركات',
            'blocks' => 'architecture',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-021',
            'type' => 'ACC',
            'title_en' => 'Tax on commissions / withholding',
            'title_ar' => 'الضريبة على العمولات والاستقطاع',
            'blocks' => 'COMM, TAX',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-022',
            'type' => 'HUM',
            'title_en' => 'Customer/supplier portal',
            'title_ar' => 'بوابة العملاء والموردين',
            'blocks' => 'scope',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-023',
            'type' => 'HUM',
            'title_en' => 'Payment methods and cash reconciliation',
            'title_ar' => 'طرق الدفع ومطابقة النقدية',
            'blocks' => 'PAY',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-024',
            'type' => 'HUM',
            'title_en' => 'Data retention and PII',
            'title_ar' => 'الاحتفاظ بالبيانات وخصوصية البيانات',
            'blocks' => 'CORE-006, CRM, INT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-025',
            'type' => 'ACC',
            'title_en' => 'Negative-margin and zero-value bookings',
            'title_ar' => 'الحجوزات بالسالب أو بدون قيمة',
            'blocks' => 'BOOK, COMM',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-026',
            'type' => 'HUM',
            'title_en' => 'Notification channels',
            'title_ar' => 'قنوات الإشعارات',
            'blocks' => 'CORE, CRM',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-027',
            'type' => 'ARC',
            'title_en' => 'Idempotency scope',
            'title_ar' => 'نطاق منع التكرار',
            'blocks' => 'ALL',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-028',
            'type' => 'ACC',
            'title_en' => 'Booking invoice document',
            'title_ar' => 'مستند فاتورة الحجز',
            'blocks' => 'BOOK, RPT, TAX',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-029',
            'type' => 'HUM',
            'title_en' => 'Language of legal documents',
            'title_ar' => 'لغة المستندات القانونية',
            'blocks' => 'RPT, UI',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-030',
            'type' => 'HUM',
            'title_en' => 'Reference data seeding',
            'title_ar' => 'تهيئة البيانات المرجعية',
            'blocks' => 'FOUNDATION',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-031',
            'type' => 'ACC',
            'title_en' => 'Cash and transfer policy',
            'title_ar' => 'سياسة النقدية والتحويلات',
            'blocks' => 'TE-018, PAY, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-032',
            'type' => 'ACC',
            'title_en' => 'Expense approval and input tax',
            'title_ar' => 'اعتماد المصروفات وضريبة المدخلات',
            'blocks' => 'TE-019, TAX, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-033',
            'type' => 'ACC',
            'title_en' => 'Opening balances cutover',
            'title_ar' => 'ترحيل الأرصدة الافتتاحية',
            'blocks' => 'TE-017, DATA',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-034',
            'type' => 'ACC',
            'title_en' => 'Year-end close and reopening',
            'title_ar' => 'إقفال نهاية السنة وإعادة الفتح',
            'blocks' => 'TE-021, FIN, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-035',
            'type' => 'ACC',
            'title_en' => 'Employee advances & custody control accounts',
            'title_ar' => 'حسابات الضبط للسلف والعهدة للموظفين',
            'blocks' => 'TE-021, PARTIES, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-036',
            'type' => 'ACC',
            'title_en' => 'Voucher tax treatment and correction policy',
            'title_ar' => 'معالجة الضريبة والتصحيح في السندات',
            'blocks' => 'TE-022, TAX, FIN',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-037',
            'type' => 'ACC',
            'title_en' => 'Bank reconciliation matching and interest account',
            'title_ar' => 'مطابقة البنك وحساب الفوائد',
            'blocks' => 'TE-023, RPT, FIN',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-038',
            'type' => 'ACC',
            'title_en' => 'Accrual, prepayment and deferred revenue recognition policy',
            'title_ar' => 'سياسة الاعتراف بالمستحقات والمصروفات المدفوعة مقدماً والإيرادات المؤجلة',
            'blocks' => 'TE-024, FIN, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-039',
            'type' => 'ACC',
            'title_en' => 'Fixed-asset depreciation and disposal policy',
            'title_ar' => 'سياسة إهلاك واستبعاد الأصول الثابتة',
            'blocks' => 'TE-025, FIN, RPT',
            'status' => 'decided',
        ],
        [
            'id' => 'DEC-040',
            'type' => 'HUM/ARC',
            'title_en' => 'Report export formats',
            'title_ar' => 'تنسيقات تصدير التقارير',
            'blocks' => 'TE-028, RPT',
            'status' => 'decided',
        ],
    ];

    /** @return array<int, array{id: string, type: string, title_en: string, title_ar: string, blocks: string, status: string}> */
    public function all(): array
    {
        return self::DECISIONS;
    }

    /**
     * Stored responses keyed by decision id (settings table, group `decisions`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolutions(): array
    {
        $result = [];

        foreach (Setting::query()->where('group', self::SETTINGS_GROUP)->get(['key', 'value']) as $row) {
            $decoded = json_decode((string) $row->value, true);

            if (is_array($decoded)) {
                $result[$row->key] = $decoded;
            }
        }

        return $result;
    }

    /** Decisions not yet decided in the registry and not approved by a stored response. */
    public function unresolved(): array
    {
        $resolutions = $this->resolutions();

        return array_values(array_filter(
            self::DECISIONS,
            static fn (array $decision): bool => $decision['status'] !== 'decided'
                && ($resolutions[$decision['id']]['status'] ?? null) !== 'approved',
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     * @return array<int, array<string, mixed>>
     */
    public function withResolutions(array $decisions): array
    {
        $resolutions = $this->resolutions();

        return array_map(
            static fn (array $decision): array => $decision + ['resolution' => $resolutions[$decision['id']] ?? null],
            $decisions,
        );
    }

    public function exists(string $id): bool
    {
        return in_array($id, array_column(self::DECISIONS, 'id'), true);
    }
}
