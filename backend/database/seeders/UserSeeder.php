<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Demonstration users (admin/accountant/sales/viewer @example.com).
 *
 * These accounts exist only for the isolated demo and for local/test
 * environments. The seeder FAILS CLOSED:
 *   - it refuses to run in any other environment (production, staging, ...);
 *     real installations create their first administrator with
 *     `php artisan erp:install`;
 *   - it refuses to run without an explicit DEMO_PASSWORD. There is no
 *     built-in default password.
 */
class UserSeeder extends Seeder
{
    /** True only inside the disposable demo container (see docker-compose.demo.coolify.yml). */
    public static function isIsolatedDemo(): bool
    {
        $connection = config('database.default');

        return filter_var(config('demo.enabled'), FILTER_VALIDATE_BOOL)
            && config('app.env') === 'demo'
            && config("database.connections.{$connection}.host") === 'demo-db'
            && config("database.connections.{$connection}.database") === 'demo_erp';
    }

    /**
     * Throws unless demo users may be created here: the isolated demo, or an
     * explicitly local/testing environment.
     */
    public static function assertDemoUsersAllowed(): void
    {
        if (! self::isIsolatedDemo() && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Refusing to seed demo users with a shared password outside the isolated demo or a local/testing '
                .'environment. Create the first administrator with: php artisan erp:install',
            );
        }
    }

    public function run(): void
    {
        self::assertDemoUsersAllowed();

        $password = config('demo.password');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException(
                'DEMO_PASSWORD is not set. Demo users are never created with a default password; set DEMO_PASSWORD explicitly.',
            );
        }

        $branches = Branch::query()->orderBy('id')->get();

        $users = [
            ['name' => 'مدير النظام', 'email' => 'admin@example.com', 'role' => 'admin', 'branches' => $branches],
            ['name' => 'المحاسب', 'email' => 'accountant@example.com', 'role' => 'accountant', 'branches' => $branches],
            ['name' => 'مسؤول المبيعات', 'email' => 'sales@example.com', 'role' => 'sales', 'branches' => $branches->take(1)],
            ['name' => 'مستخدم للقراءة', 'email' => 'viewer@example.com', 'role' => 'viewer', 'branches' => $branches->take(1)],
        ];

        // In a disposable demo, accounts must always match the configured
        // credentials after a scheduled reset or an interrupted previous seed.
        // Never overwrite existing passwords outside the isolated demo.
        $isIsolatedDemo = self::isIsolatedDemo();
        $hash = Hash::make($password);

        foreach ($users as $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => $hash,
                    'locale' => 'ar',
                    'is_active' => true,
                ],
            );

            if ($isIsolatedDemo) {
                $user->forceFill([
                    'name' => $definition['name'],
                    'password' => $hash,
                    'is_active' => true,
                ])->save();
            }

            $user->syncRoles([$definition['role']]);
            $user->branches()->syncWithoutDetaching($definition['branches']->pluck('id')->all());
        }
    }
}
