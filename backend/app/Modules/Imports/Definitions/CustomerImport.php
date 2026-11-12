<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Core\Services\AuditService;
use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Parties\Models\Customer;

class CustomerImport extends AbstractPartyImport
{
    public function __construct(private readonly AuditService $audit) {}

    public function entity(): string
    {
        return 'customers';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Customers', 'ar' => 'العملاء'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('type', 'Type', 'النوع', 'enum', false, ['values' => ['individual', 'company'], 'map' => ['فرد' => 'individual', 'شركة' => 'company']], 'individual', 'individual or company (default individual).', 'فرد أو شركة (الافتراضي فرد).'),
            new ColumnDef('name_ar', 'Name (Arabic)', 'الاسم (عربي)', 'string', true, ['max' => 255], 'شركة النيل للسياحة', 'Required.', 'مطلوب.', ['name (ar)']),
            new ColumnDef('name_en', 'Name (English)', 'الاسم (إنجليزي)', 'string', true, ['max' => 255], 'Nile Tours', 'Required.', 'مطلوب.', ['name (en)']),
            new ColumnDef('phone', 'Phone', 'الهاتف', 'phone', false, [], '+201001234567', 'Digits, spaces, + ( ) - only.', 'أرقام ومسافات و + ( ) - فقط.'),
            new ColumnDef('email', 'Email', 'البريد الإلكتروني', 'email', false, [], 'info@example.com'),
            new ColumnDef('tax_id', 'Tax ID', 'الرقم الضريبي', 'string', false, ['max' => 50], '123456789', 'First choice for duplicate detection.', 'أول معيار لاكتشاف التكرار.'),
            new ColumnDef('credit_limit', 'Credit limit', 'حد الائتمان', 'money', false, ['currency_key' => 'currency'], '5000.00', 'Major units, in the customer currency.', 'بالوحدة الأساسية وبعملة العميل.'),
            new ColumnDef('currency', 'Currency', 'العملة', 'currency', false, [], 'EGP', 'Defaults to the base currency. Cannot be changed on update.', 'الافتراضي هو العملة الأساسية. لا يتغير عند التحديث.'),
            new ColumnDef('is_active', 'Active', 'نشط', 'bool', false, [], 'yes', 'yes/no (default yes).', 'نعم/لا (الافتراضي نعم).', ['active']),
        ];
    }

    protected function table(): string
    {
        return 'customers';
    }

    protected function hasTaxId(): bool
    {
        return true;
    }

    protected function createPermission(): string
    {
        return 'customers.create';
    }

    protected function updatePermission(): string
    {
        return 'customers.update';
    }

    protected function summary(array $values): array
    {
        return ['name_en' => $values['name_en'], 'name_ar' => $values['name_ar'], 'phone' => $values['phone'], 'email' => $values['email']];
    }

    protected function createRecord(array $values, ImportContext $ctx): void
    {
        $customer = Customer::query()->create([
            'type' => $values['type'] ?? 'individual',
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'tax_id' => $values['tax_id'],
            'branch_id' => $ctx->branch->id,
            'credit_limit_minor' => $values['credit_limit'] ?? 0,
            'currency_code' => $values['currency'] ?? $ctx->baseCurrency,
            'is_active' => $values['is_active'] ?? true,
        ]);

        $this->audit->log('customer.created', $customer, null, $customer->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }

    protected function updateRecord(int $id, array $values, ImportContext $ctx): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($id);

        if ($values['currency'] !== null && $values['currency'] !== $customer->currency_code) {
            throw new \DomainException('The currency of an existing customer cannot be changed by import.');
        }

        $before = $customer->toApiArray();
        $changes = array_filter([
            'type' => $values['type'],
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'tax_id' => $values['tax_id'],
            'credit_limit_minor' => $values['credit_limit'],
            'is_active' => $values['is_active'],
        ], fn ($value) => $value !== null);
        $customer->update($changes);

        $this->audit->log('customer.updated', $customer, $before, $customer->refresh()->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }
}
