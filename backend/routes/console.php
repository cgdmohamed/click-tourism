<?php

use App\Http\Controllers\Api\HealthController;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Database\Seeders\ProductionBootstrapSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Lets /api/v1/health report whether the scheduler is running.
Schedule::call(fn () => Cache::put(HealthController::HEARTBEAT_KEY, now()->toIso8601String(), 600))
    ->name('scheduler-heartbeat')
    ->everyMinute();

// Posts due accrual/prepayment/deferred-revenue events. Reruns are safe and
// closed periods go to the exception queue instead of being bypassed.
Schedule::command('schedules:post-due')
    ->name('schedules-post-due')
    ->dailyAt('00:15')
    ->withoutOverlapping();

// Unrealized FX revaluation (DEC-008). Both are no-ops unless
// accounting.fx_revaluation_enabled is on and the accounting policy is approved.
Schedule::command('accounting:revalue --apply')
    ->name('fx-revaluation-month-end')
    ->lastDayOfMonth('23:30')
    ->withoutOverlapping();
Schedule::command('accounting:revalue --apply --reversals-only')
    ->name('fx-revaluation-reversals')
    ->dailyAt('00:30')
    ->withoutOverlapping();

/**
 * Only the dedicated disposable demo container publishes a reset window.
 * Production must never call the destructive demo reset script.
 */
Artisan::command('demo:window {--seconds=600}', function () {
    if (! config('demo.enabled') || config('app.env') !== 'demo') {
        $this->error('Demo reset window is disabled outside demo mode.');

        return 1;
    }

    $seconds = (int) $this->option('seconds');
    if ($seconds !== 600) {
        $this->error('Demo reset interval must be 600 seconds.');

        return 1;
    }

    Cache::put('demo:window', [
        'generation' => (string) Str::uuid(),
        'next_reset_at' => now()->addSeconds($seconds)->toIso8601String(),
    ], now()->addSeconds($seconds + 120));

    $this->info('New 10-minute demo window started.');

    return 0;
})->purpose('Record the active ephemeral demo window');

/**
 * Reconcile and verify all disposable demo accounts after boot and after
 * every reset. Never write to a non-demo installation.
 */
Artisan::command('demo:ensure-users {--verify-only}', function () {
    if (! config('demo.enabled') || config('app.env') !== 'demo'
        || env('DB_HOST') !== 'demo-db' || env('DB_DATABASE') !== 'demo_erp') {
        $this->error('Refusing to seed demo users outside isolated demo database.');

        return 1;
    }

    if (! $this->option('verify-only')) {
        Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);
    }

    $password = (string) env('DEMO_PASSWORD', 'password');
    foreach ([
        'admin@example.com' => 'admin',
        'accountant@example.com' => 'accountant',
        'sales@example.com' => 'sales',
        'viewer@example.com' => 'viewer',
    ] as $email => $role) {
        $user = User::query()->with('branches')->where('email', $email)->first();
        if ($user === null || ! $user->is_active || ! Hash::check($password, $user->password)
            || ! $user->hasRole($role) || $user->branches->isEmpty()) {
            $this->error("Demo user verification failed for {$email}.");

            return 1;
        }
    }

    $this->info('Demo users verified (4 accounts, credentials, roles and branches).');

    return 0;
})->purpose('Repair and verify isolated demo user accounts');

/**
 * Production bootstrap: no demo user credentials. Run interactively through
 * Coolify terminal once, after the first migration has completed.
 */
Artisan::command('setup:admin {email} {--name=Administrator}', function () {
    if (config('demo.enabled') || config('app.env') === 'demo') {
        $this->error('Production admin bootstrap cannot be used in demo mode.');

        return 1;
    }

    $email = strtolower(trim((string) $this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid admin email address.');

        return 1;
    }
    if (User::query()->where('email', $email)->exists()) {
        $this->error('This email is already registered. Use the user management workflow.');

        return 1;
    }

    $password = (string) $this->secret('Enter an initial admin password (minimum 12 characters)');
    if (strlen($password) < 12) {
        $this->error('Password must contain at least 12 characters.');

        return 1;
    }

    Artisan::call('db:seed', ['--class' => ProductionBootstrapSeeder::class, '--force' => true]);

    $user = User::query()->create([
        'name' => (string) $this->option('name'),
        'email' => $email,
        'password' => Hash::make($password),
        'locale' => 'ar',
        'is_active' => true,
    ]);
    $user->assignRole('admin');
    $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
    $user->branches()->syncWithoutDetaching([$branch->id]);

    $this->info('Initial production admin created. No demo accounts were installed.');

    return 0;
})->purpose('Securely initialize production reference data and first admin');
