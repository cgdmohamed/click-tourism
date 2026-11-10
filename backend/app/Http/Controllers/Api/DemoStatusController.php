<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DemoStatusController extends ApiController
{
    public function show(): JsonResponse
    {
        $enabled = (bool) config('demo.enabled');
        $window = $enabled ? Cache::get('demo:window') : null;

        return $this->ok([
            'enabled' => $enabled,
            'reset_seconds' => $enabled ? (int) config('demo.reset_seconds', 600) : null,
            'next_reset_at' => is_array($window) ? ($window['next_reset_at'] ?? null) : null,
            'generation' => is_array($window) ? ($window['generation'] ?? null) : null,
        ]);
    }
}
