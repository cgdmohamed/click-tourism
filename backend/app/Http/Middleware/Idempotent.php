<?php

namespace App\Http\Middleware;

use App\Modules\Integrations\Services\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Idempotent
{
    public function __construct(private readonly IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->idempotency->begin($request);

        if ($state === null) {
            return $next($request);
        }

        ['record' => $record, 'created' => $created] = $state;

        if (! $this->idempotency->matches($record, $request)) {
            return $this->idempotency->conflict();
        }

        if ($stored = $this->idempotency->storedResponse($record)) {
            $stored->headers->set('Idempotent-Replayed', 'true');

            return $stored;
        }

        if (! $created && $record->created_at !== null && $record->created_at->gt(now()->subMinutes(2))) {
            return $this->idempotency->inProgress();
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->idempotency->abort($record);
            throw $exception;
        }

        $this->idempotency->complete($record, $response);

        return $response;
    }
}
