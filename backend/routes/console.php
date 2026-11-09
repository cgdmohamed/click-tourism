<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Lets /api/v1/health report whether the scheduler is running.
Schedule::call(fn () => Cache::put(HealthController::HEARTBEAT_KEY, now()->toIso8601String(), 600))
    ->name('scheduler-heartbeat')
    ->everyMinute();
