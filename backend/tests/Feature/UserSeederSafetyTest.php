<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class UserSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Branch::query()->create(['code' => 'HQ', 'name_ar' => 'HQ', 'name_en' => 'HQ', 'accounting_mode' => 'dimension', 'is_active' => true]);
    }

    public function test_seeding_without_an_explicit_demo_password_fails_closed(): void
    {
        $this->prepare();
        config(['demo.password' => null]);

        try {
            $this->seed(UserSeeder::class);
            $this->fail('UserSeeder must refuse to run without DEMO_PASSWORD.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DEMO_PASSWORD is not set', $e->getMessage());
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_there_is_no_built_in_password(): void
    {
        $this->prepare();
        config(['demo.password' => 'Demo-only-Pw-1']);
        $this->seed(UserSeeder::class);

        $this->assertSame(4, User::query()->count());
        foreach (User::query()->get() as $user) {
            $this->assertFalse(Hash::check('password', $user->password));
            $this->assertTrue(Hash::check('Demo-only-Pw-1', $user->password));
        }
    }

    public function test_it_refuses_production_even_with_a_password(): void
    {
        $this->prepare();
        config(['demo.password' => 'Demo-only-Pw-1']);
        $this->app['env'] = 'production';

        try {
            app(UserSeeder::class)->run();
            $this->fail('UserSeeder must refuse production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('erp:install', $e->getMessage());
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_full_seeder_refuses_production_before_writing_anything(): void
    {
        config(['demo.password' => 'Demo-only-Pw-1']);
        $this->app['env'] = 'production';

        try {
            app(DatabaseSeeder::class)->run();
            $this->fail('DatabaseSeeder must refuse production.');
        } catch (RuntimeException) {
            $this->assertSame(0, Branch::query()->count());
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_isolated_demo_guard_is_recognised(): void
    {
        $connection = config('database.default');
        config([
            'demo.enabled' => true,
            'app.env' => 'demo',
            "database.connections.{$connection}.host" => 'demo-db',
            "database.connections.{$connection}.database" => 'demo_erp',
        ]);
        $this->app['env'] = 'production';

        $this->assertTrue(UserSeeder::isIsolatedDemo());
        UserSeeder::assertDemoUsersAllowed();
    }
}
