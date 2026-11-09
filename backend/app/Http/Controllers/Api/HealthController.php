<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\DecisionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends ApiController
{
    private const VERSION = '0.1.0';

    public function show(DecisionRegistry $decisions): JsonResponse
    {
        $database = $this->checkDatabase();
        $cache = $this->checkCache();
        $queue = $this->checkQueue();

        $status = 'ok';
        if ($database['status'] !== 'ok') {
            $status = 'down';
        } elseif ($cache['status'] !== 'ok' || $queue['status'] !== 'ok') {
            $status = 'degraded';
        }

        return $this->ok([
            'status' => $status,
            'version' => self::VERSION,
            'checks' => [
                'app' => ['status' => 'ok'],
                'database' => $database,
                'cache' => $cache,
                'queue' => $queue,
            ],
            'unresolved_decisions' => $decisions->unresolved(),
        ], $status === 'down' ? 503 : 200);
    }

    /** @return array{status: string, latency_ms: int} */
    private function checkDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable) {
            return [
                'status' => 'down',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /** @return array{status: string, driver: string} */
    private function checkCache(): array
    {
        $driver = (string) config('cache.default');

        try {
            Cache::put('health:probe', '1', 5);
            $ok = Cache::get('health:probe') === '1';

            return [
                'status' => $ok ? 'ok' : 'unavailable',
                'driver' => $driver,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'driver' => $driver,
            ];
        }
    }

    /** @return array{status: string, driver: string} */
    private function checkQueue(): array
    {
        $configured = config('queue.default');
        $driver = $configured === null ? 'sync' : (string) $configured;

        if ($driver === 'sync' || $driver === 'null') {
            return [
                'status' => 'ok',
                'driver' => $driver,
            ];
        }

        try {
            Queue::size();

            return [
                'status' => 'ok',
                'driver' => $driver,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'driver' => $driver,
            ];
        }
    }
}
