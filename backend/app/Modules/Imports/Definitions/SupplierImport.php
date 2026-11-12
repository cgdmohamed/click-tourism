<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Core\Services\AuditService;
use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Parties\Models\Supplier;

class SupplierImport extends AbstractPartyImport
{
    public function __construct(private readonly AuditService $audit) {}

    public function entity(): string
    {
        return 'suppliers';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Suppliers', 'ar' => 'الموردون'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('category', 'Category', 'التصنيف', 'string', false, ['max' => 30], 'hotel', 'flight, hotel, transport, visa, agent, other... (max 30 chars, default other).', 'طيران، فندق، نقل، تأشيرات، وكيل، أخرى... (الافتراضي أخرى).'),
            new ColumnDef('name_ar', 'Name (Arabic)', 'الاسم (عربي)', 'string', true, ['max' => 255], 'فندق الأهرام', 'Required.', 'مطلوب.', ['name (ar)']),
            new ColumnDef('name_en', 'Name (English)', 'الاسم (إنجليزي)', 'string', true, ['max' => 255], 'Pyramids Hotel', 'Required.', 'مطلوب.', ['name (en)']),
            new ColumnDef('phone', 'Phone', 'الهاتف', 'phone', false, [], '+20233334444'),
            new ColumnDef('email', 'Email', 'البريد الإلكتروني', 'email', false, [], 'sales@example.com'),
            new ColumnDef('settlement_currency', 'Settlement currency', 'عملة التسوية', 'currency', false, [], 'USD', 'Defaults to the base currency.', 'الافتراضي هو العملة الأساسية.', ['currency']),
            new ColumnDef('is_active', 'Active', 'نشط', 'bool', false, [], 'yes', 'yes/no (default yes).', 'نعم/لا (الافتراضي نعم).', ['active']),
        ];
    }

    protected function table(): string
    {
        return 'suppliers';
    }

    protected function hasTaxId(): bool
    {
        return false;
    }

    protected function createPermission(): string
    {
        return 'suppliers.create';
    }

    protected function updatePermission(): string
    {
        return 'suppliers.update';
    }

    protected function summary(array $values): array
    {
        return ['name_en' => $values['name_en'], 'name_ar' => $values['name_ar'], 'phone' => $values['phone'], 'email' => $values['email']];
    }

    protected function createRecord(array $values, ImportContext $ctx): void
    {
        $supplier = Supplier::query()->create([
            'category' => $values['category'] ?? 'other',
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'settlement_currency' => $values['settlement_currency'] ?? $ctx->baseCurrency,
            'branch_id' => $ctx->branch->id,
            'is_active' => $values['is_active'] ?? true,
        ]);

        $this->audit->log('supplier.created', $supplier, null, $supplier->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }

    protected function updateRecord(int $id, array $values, ImportContext $ctx): void
    {
        $supplier = Supplier::query()->lockForUpdate()->findOrFail($id);
        $before = $supplier->toApiArray();
        $supplier->update(array_filter([
            'category' => $values['category'],
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'settlement_currency' => $values['settlement_currency'],
            'is_active' => $values['is_active'],
        ], fn ($value) => $value !== null));

        $this->audit->log('supplier.updated', $supplier, $before, $supplier->refresh()->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }
}
