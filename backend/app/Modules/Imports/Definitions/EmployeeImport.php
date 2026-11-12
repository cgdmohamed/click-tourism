<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Services\EmployeeFinanceService;
use Illuminate\Support\Facades\DB;

/**
 * Employees are keyed by their globally unique internal code, so a code that
 * exists in another branch is reported as an error instead of being touched.
 */
class EmployeeImport extends AbstractRowDefinition
{
    /** @var array<string, array{id: int, branch_id: ?int}> */
    private array $index = [];

    public function __construct(private readonly EmployeeFinanceService $employees) {}

    public function entity(): string
    {
        return 'employees';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Employees', 'ar' => 'الموظفون'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('internal_code', 'Employee code', 'كود الموظف', 'string', false, ['max' => 40], 'EMP-0001', 'Optional. Generated (EMP-0001, ...) when empty. When given it is the duplicate key, stored in upper case.', 'اختياري ويُنشأ تلقائياً عند تركه فارغاً. هو مفتاح التكرار عند إدخاله ويُحفظ بحروف كبيرة.', ['code', 'internal code']),
            new ColumnDef('name_ar', 'Name (Arabic)', 'الاسم (عربي)', 'string', true, ['max' => 255], 'أحمد علي', 'Required.', 'مطلوب.', ['name (ar)']),
            new ColumnDef('name_en', 'Name (English)', 'الاسم (إنجليزي)', 'string', true, ['max' => 255], 'Ahmed Ali', 'Required.', 'مطلوب.', ['name (en)']),
            new ColumnDef('category', 'Category', 'الفئة', 'enum', false, ['values' => Employee::categories(), 'map' => ['موظف' => 'staff', 'مدير' => 'executive', 'سائق' => 'driver', 'أخرى' => 'other']], 'staff', 'staff, executive, driver or other (default staff).', 'موظف، مدير، سائق أو أخرى (الافتراضي موظف).'),
            new ColumnDef('phone', 'Phone', 'الهاتف', 'phone', false, [], '+201001234567'),
            new ColumnDef('email', 'Email', 'البريد الإلكتروني', 'email', false, [], 'ahmed@example.com'),
        ];
    }

    protected function createPermission(): string
    {
        return 'employees.manage';
    }

    protected function updatePermission(): string
    {
        return 'employees.manage';
    }

    protected function prepare(ImportContext $ctx): void
    {
        $this->index = [];
        DB::table('employees')->orderBy('id')->select(['id', 'branch_id', 'internal_code', 'name_en'])
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $entry = ['id' => (int) $row->id, 'branch_id' => (int) $row->branch_id];
                    $this->index[$this->normalise((string) $row->internal_code)] = $entry;
                    $this->index['name:'.mb_strtolower(trim((string) $row->name_en))] ??= $entry;
                }
            });
    }

    protected function keyFor(array $values): string
    {
        $code = $this->normalise((string) ($values['internal_code'] ?? ''));

        // Without a code the row can only match by name (so a re-import does not duplicate it).
        return $code !== '' ? $code : 'name:'.mb_strtolower(trim((string) $values['name_en']));
    }

    protected function find(string $key): ?array
    {
        return $this->index[$key] ?? null;
    }

    protected function summary(array $values): array
    {
        return ['internal_code' => $this->normalise((string) ($values['internal_code'] ?? '')) ?: null, 'name_en' => $values['name_en'], 'name_ar' => $values['name_ar'], 'category' => $values['category'] ?? 'staff'];
    }

    protected function createRecord(array $values, ImportContext $ctx): void
    {
        $this->employees->createEmployee([
            'internal_code' => $values['internal_code'] ?? null,
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'category' => $values['category'] ?? Employee::CATEGORY_STAFF,
            'phone' => $values['phone'],
            'email' => $values['email'],
        ], $ctx->branch, $ctx->userId);
    }

    protected function updateRecord(int $id, array $values, ImportContext $ctx): void
    {
        $employee = Employee::query()->findOrFail($id);
        $this->employees->updateEmployee($employee, [
            'name_ar' => $values['name_ar'],
            'name_en' => $values['name_en'],
            'category' => $values['category'],
            'phone' => $values['phone'],
            'email' => $values['email'],
        ], $ctx->userId);
    }

    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
