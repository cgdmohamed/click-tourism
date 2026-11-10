<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
