<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\DecisionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends ApiController
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    private const HEARTBEAT_MAX_AGE_SECONDS = 180;

    /** Liveness plus dependency detail and unresolved decisions. */
    public function show(DecisionRegistry $decisions): JsonResponse
    {
        $database = $this->checkDatabase();
        $cache = $this->checkCache();
        $redis = $this->checkRedis();
        $queue = $this->checkQueue();
        $scheduler = $this->checkScheduler();

        $status = 'ok';
        if ($database['status'] !== 'ok') {
            $status = 'down';
        } elseif (array_intersect(
            [$cache['status'], $redis['status'], $queue['status'], $scheduler['status']],
            ['unavailable', 'down', 'stale'],
        ) !== []) {
            $status = 'degraded';
        }

        return $this->ok([
            'status' => $status,
            'version' => (string) config('app.version'),
            'checks' => [
                'app' => ['status' => 'ok'],
                'database' => $database,
                'cache' => $cache,
                'redis' => $redis,
                'queue' => $queue,
                'scheduler' => $scheduler,
            ],
            'unresolved_decisions' => $decisions->unresolved(),
        ], $status === 'down' ? 503 : 200);
    }

    /** Readiness: can this instance serve traffic (database reachable)? */
    public function ready(): JsonResponse
    {
        $database = $this->checkDatabase();
        $ready = $database['status'] === 'ok';

        return $this->ok(['ready' => $ready], $ready ? 200 : 503);
    }

    /** @return array{status: string, latency_ms: int} */
    private function checkDatabase(): array
    {
        $started = microtime(true);
        $status = 'ok';

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $status = 'down';
        }

        return [
            'status' => $status,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** @return array{status: string, driver: string} */
    private function checkCache(): array
    {
        $driver = (string) config('cache.default');

        try {
            Cache::put('health:probe', '1', 5);
            $ok = Cache::get('health:probe') === '1';

            return ['status' => $ok ? 'ok' : 'unavailable', 'driver' => $driver];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'driver' => $driver];
        }
    }

    /** @return array{status: string} */
    private function checkRedis(): array
    {
        $uses = config('cache.default') === 'redis' || config('queue.default') === 'redis';

        if (! $uses) {
            return ['status' => 'not_configured'];
        }

        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'down'];
        }
    }

    /** @return array{status: string, driver: string, failed_jobs: int} */
    private function checkQueue(): array
    {
        $configured = config('queue.default');
        $driver = $configured === null ? 'sync' : (string) $configured;
        $failed = $this->failedJobs();

        if ($driver === 'sync' || $driver === 'null') {
            return ['status' => 'ok', 'driver' => $driver, 'failed_jobs' => $failed];
        }

        try {
            Queue::size();

            return ['status' => 'ok', 'driver' => $driver, 'failed_jobs' => $failed];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'driver' => $driver, 'failed_jobs' => $failed];
        }
    }

    private function failedJobs(): int
    {
        try {
            return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * `unknown` means the scheduler never reported (it may be intentionally
     * disabled); `stale` means it reported before and has stopped.
     *
     * @return array{status: string, last_run_at: ?string}
     */
    private function checkScheduler(): array
    {
        try {
            $last = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return ['status' => 'unknown', 'last_run_at' => null];
        }

        if (! is_string($last)) {
            return ['status' => 'unknown', 'last_run_at' => null];
        }

        try {
            $age = Carbon::parse($last)->diffInSeconds(now(), true);
        } catch (Throwable) {
            return ['status' => 'unknown', 'last_run_at' => null];
        }

        return [
            'status' => $age <= self::HEARTBEAT_MAX_AGE_SECONDS ? 'ok' : 'stale',
            'last_run_at' => $last,
        ];
    }
}
