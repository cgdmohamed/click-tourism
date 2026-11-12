<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Core\Services\AuditService;
use App\Modules\Imports\Support\ColumnDef;
use App\Modules\Imports\Support\ImportContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TourPackageImport extends AbstractRowDefinition
{
    private const CATEGORIES = ['domestic', 'international', 'umrah', 'hajj', 'corporate', 'day_trip', 'other'];

    private const EXECUTION_TYPES = ['fixed', 'private', 'standalone'];

    /** @var array<string, array{id: int, branch_id: ?int}> */
    private array $index = [];

    public function __construct(private readonly AuditService $audit) {}

    public function entity(): string
    {
        return 'tour-packages';
    }

    public function version(): int
    {
        return 1;
    }

    public function labels(): array
    {
        return ['en' => 'Tour packages', 'ar' => 'الباقات السياحية'];
    }

    public function columns(): array
    {
        return [
            new ColumnDef('code', 'Code', 'الكود', 'string', false, ['max' => 50], 'UMRAH-RAMADAN', 'Optional. Generated when empty. Duplicate key when given; otherwise name + destination.', 'اختياري ويُنشأ تلقائياً عند تركه فارغاً. هو مفتاح التكرار عند إدخاله وإلا فالاسم + الوجهة.'),
            new ColumnDef('name', 'Name', 'الاسم', 'string', true, ['max' => 200], 'Umrah Ramadan 10 nights', 'Required.', 'مطلوب.'),
            new ColumnDef('category', 'Category', 'التصنيف', 'enum', true, ['values' => self::CATEGORIES], 'umrah', implode(', ', self::CATEGORIES).'.', implode('، ', self::CATEGORIES).'.'),
            new ColumnDef('execution_type', 'Execution type', 'نوع التنفيذ', 'enum', true, ['values' => self::EXECUTION_TYPES], 'fixed', implode(', ', self::EXECUTION_TYPES).'.', implode('، ', self::EXECUTION_TYPES).'.'),
            new ColumnDef('destination', 'Destination', 'الوجهة', 'string', false, ['max' => 200], 'Makkah'),
            new ColumnDef('description', 'Description', 'الوصف', 'string', false, ['max' => 2000], ''),
            new ColumnDef('currency', 'Currency', 'العملة', 'currency', true, [], 'SAR', 'Required. Cannot be changed on update.', 'مطلوب. لا يتغير عند التحديث.'),
            new ColumnDef('base_price', 'Base price', 'سعر البيع', 'money', true, ['currency_key' => 'currency'], '12500.00', 'Major units.', 'بالوحدة الأساسية.'),
            new ColumnDef('estimated_cost', 'Estimated cost', 'التكلفة التقديرية', 'money', true, ['currency_key' => 'currency'], '9800.00', 'Major units.', 'بالوحدة الأساسية.'),
            new ColumnDef('is_active', 'Active', 'نشط', 'bool', false, [], 'yes', 'yes/no (default yes).', 'نعم/لا (الافتراضي نعم).', ['active']),
        ];
    }

    protected function createPermission(): string
    {
        return 'bookings.create';
    }

    protected function updatePermission(): string
    {
        return 'bookings.update';
    }

    protected function prepare(ImportContext $ctx): void
    {
        $this->index = [];
        if ($ctx->branch === null) {
            return;
        }

        DB::table('tour_packages')->where('branch_id', $ctx->branch->id)->orderBy('id')
            ->select(['id', 'branch_id', 'code', 'name', 'destination'])
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $entry = ['id' => (int) $row->id, 'branch_id' => (int) $row->branch_id];
                    $this->index['code:'.strtoupper((string) $row->code)] = $entry;
                    $this->index['name:'.$this->nameKey((string) $row->name, $row->destination)] ??= $entry;
                }
            });
    }

    protected function keyFor(array $values): string
    {
        return $values['code'] !== null
            ? 'code:'.strtoupper($values['code'])
            : 'name:'.$this->nameKey($values['name'], $values['destination']);
    }

    protected function find(string $key): ?array
    {
        return $this->index[$key] ?? null;
    }

    protected function summary(array $values): array
    {
        return ['code' => $values['code'], 'name' => $values['name'], 'category' => $values['category'], 'destination' => $values['destination']];
    }

    protected function createRecord(array $values, ImportContext $ctx): void
    {
        $package = TourPackage::query()->create([
            'branch_id' => $ctx->branch->id,
            'code' => $values['code'] !== null ? strtoupper($values['code']) : 'TOUR-'.Str::ulid(),
            'name' => $values['name'],
            'category' => $values['category'],
            'execution_type' => $values['execution_type'],
            'description' => $values['description'],
            'destination' => $values['destination'],
            'currency_code' => $values['currency'],
            'base_price_minor' => $values['base_price'],
            'estimated_cost_minor' => $values['estimated_cost'],
            'is_active' => $values['is_active'] ?? true,
            'created_by' => $ctx->userId,
        ]);

        $this->audit->log('tour_package.created', $package, null, $package->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }

    protected function updateRecord(int $id, array $values, ImportContext $ctx): void
    {
        $package = TourPackage::query()->lockForUpdate()->findOrFail($id);

        if ($values['currency'] !== $package->currency_code) {
            throw new \DomainException('The currency of an existing package cannot be changed by import.');
        }

        $before = $package->toApiArray();
        $package->update(array_filter([
            'name' => $values['name'],
            'category' => $values['category'],
            'execution_type' => $values['execution_type'],
            'description' => $values['description'],
            'destination' => $values['destination'],
            'base_price_minor' => $values['base_price'],
            'estimated_cost_minor' => $values['estimated_cost'],
            'is_active' => $values['is_active'],
        ], fn ($value) => $value !== null));

        $this->audit->log('tour_package.updated', $package, $before, $package->refresh()->toApiArray() + ['import_job' => $ctx->jobPublicId], $ctx->userId);
    }

    private function nameKey(string $name, ?string $destination): string
    {
        return mb_strtolower(trim($name)).'|'.mb_strtolower(trim((string) $destination));
    }
}
