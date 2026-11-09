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

        foreach ($users as $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make(env('DEMO_PASSWORD', 'password')),
                    'locale' => 'ar',
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$definition['role']]);
            $user->branches()->syncWithoutDetaching($definition['branches']->pluck('id')->all());
        }
    }
}
