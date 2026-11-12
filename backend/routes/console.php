<?php

use App\Http\Controllers\Api\HealthController;
use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
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

// Work order 02: keep the Sanctum token table small. Tokens expired for
// more than a week are deleted; the guard already rejects them earlier.
Schedule::command('sanctum:prune-expired --hours=168')
    ->name('sanctum-prune-expired')
    ->dailyAt('02:45')
    ->withoutOverlapping();

// 24-hour lead inactivity reassignment (GAP-CRM-01). The service measures
// inactivity in configured business hours and is safe to rerun.
Schedule::command('crm:reassign-stale-leads')
    ->name('crm-reassign-stale-leads')
    ->hourlyAt(5)
    ->withoutOverlapping();

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

// Optional Meta / WhatsApp integrations: publish queued outbox rows and re-queue
// lost jobs. No-op while the integrations are disabled or idle.
Schedule::command('integrations:dispatch-outbox')
    ->name('integrations-dispatch-outbox')
    ->everyMinute()
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

    $password = (string) config('demo.password');
    if ($password === '') {
        $this->error('DEMO_PASSWORD is not set; demo users are never created with a default password.');

        return 1;
    }
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
 * Deprecated alias kept for existing runbooks: delegates to `erp:install`,
 * which also creates the company profile and fiscal calendar. The password is
 * read from ERP_ADMIN_PASSWORD(_FILE) or a masked prompt, never from a default.
 */
Artisan::command('setup:admin {email} {--name=Administrator}', function () {
    $this->warn('setup:admin is deprecated; use `php artisan erp:install`.');

    return $this->call('erp:install', array_filter([
        '--admin-email' => $this->argument('email'),
        '--admin-name' => $this->option('name'),
    ]));
})->purpose('Deprecated alias of erp:install');

/**
 * Re-applies roles and permissions on an existing installation after an
 * upgrade. Idempotent: creates permissions introduced by the release and
 * re-attaches them to the built-in roles without touching users.
 */
Artisan::command('roles:sync', function () {
    Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

    $this->info('Roles and permissions synchronised. Users with cached permission data may need to sign in again.');

    return 0;
})->purpose('Create missing permissions and refresh the built-in role assignments');
