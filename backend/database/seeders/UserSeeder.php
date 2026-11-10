<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Baseline users for a fresh installation.
 *
 * The passwords below are development defaults and MUST be changed during
 * deployment. Only the admin account is required in production; the others
 * demonstrate role separation and are safe to delete.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::query()->orderBy('id')->get();

        $users = [
            ['name' => 'مدير النظام', 'email' => 'admin@example.com', 'role' => 'admin', 'branches' => $branches],
            ['name' => 'المحاسب', 'email' => 'accountant@example.com', 'role' => 'accountant', 'branches' => $branches],
            ['name' => 'مسؤول المبيعات', 'email' => 'sales@example.com', 'role' => 'sales', 'branches' => $branches->take(1)],
            ['name' => 'مستخدم للقراءة', 'email' => 'viewer@example.com', 'role' => 'viewer', 'branches' => $branches->take(1)],
        ];

        // In a disposable demo, accounts must always match the configured
        // credentials after a scheduled reset or an interrupted previous seed.
        // Never overwrite real installation passwords on ordinary seeding.
        $isIsolatedDemo = filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOL)
            && env('APP_ENV') === 'demo'
            && env('DB_HOST') === 'demo-db'
            && env('DB_DATABASE') === 'demo_erp';
        $demoPassword = $isIsolatedDemo ? Hash::make(env('DEMO_PASSWORD', 'password')) : null;

        foreach ($users as $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => $demoPassword ?? Hash::make(env('DEMO_PASSWORD', 'password')),
                    'locale' => 'ar',
                    'is_active' => true,
                ],
            );

            if ($isIsolatedDemo) {
                $user->forceFill([
                    'name' => $definition['name'],
                    'password' => $demoPassword,
                    'is_active' => true,
                ])->save();
            }

            $user->syncRoles([$definition['role']]);
            $user->branches()->syncWithoutDetaching($definition['branches']->pluck('id')->all());
        }
    }
}
