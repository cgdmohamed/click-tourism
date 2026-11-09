<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Default branch layout. Every installation starts with one branch; a second
 * branch is seeded so branch scoping can be verified out of the box.
 */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['code' => 'HQ', 'name_ar' => 'الفرع الرئيسي', 'name_en' => 'Head Office', 'accounting_mode' => 'dimension'],
            ['code' => 'ALX', 'name_ar' => 'فرع الإسكندرية', 'name_en' => 'Alexandria Branch', 'accounting_mode' => 'dimension'],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(
                ['code' => $branch['code']],
                $branch + ['is_active' => true],
            );
        }
    }
}
