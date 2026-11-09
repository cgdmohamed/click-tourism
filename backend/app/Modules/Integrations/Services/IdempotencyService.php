<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Request-level idempotency for money-moving endpoints (ADR-0006).
 * A key is scoped to the actor; replaying it returns the first stored
 * response, while reusing it with a different payload is a conflict.
 */
class IdempotencyService
{
    /**
     * @return array{record: IdempotencyKey, created: bool}|null
     */
    public function begin(Request $request): ?array
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $actorId = $request->user()?->id;
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyKey::query()
            ->where('actor_id', $actorId)
            ->where('key', $key)
            ->first();

        if ($existing) {
            return ['record' => $existing, 'created' => false];
        }

        try {
            $record = IdempotencyKey::query()->create([
                'actor_id' => $actorId,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $hash,
                'expires_at' => now()->addDay(),
            ]);

            return ['record' => $record, 'created' => true];
        } catch (QueryException) {
            // Another concurrent request won the race; reuse its record.
            $record = IdempotencyKey::query()
                ->where('actor_id', $actorId)
                ->where('key', $key)
                ->first();

            return $record ? ['record' => $record, 'created' => false] : null;
        }
    }

    public function matches(IdempotencyKey $record, Request $request): bool
    {
        return hash_equals(
            $record->request_hash,
            hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent()),
        );
    }

    public function storedResponse(IdempotencyKey $record): ?SymfonyResponse
    {
        if ($record->response_status === null) {
            return null;
        }

        return new JsonResponse(
            json_decode((string) $record->response_body, true),
            (int) $record->response_status,
        );
    }

    public function complete(IdempotencyKey $record, SymfonyResponse $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500) {
            $record->delete();

            return;
        }

        $content = $response->getContent();

        $record->update([
            'response_status' => $status,
            'response_body' => $content === false ? null : $content,
        ]);
    }

    public function abort(IdempotencyKey $record): void
    {
        if ($record->response_status === null) {
            $record->delete();
        }
    }

    public function conflict(): JsonResponse
    {
        return response()->json([
            'message' => 'This idempotency key was already used with a different payload.',
            'code' => 'idempotency_conflict',
        ], 409);
    }

    public function inProgress(): JsonResponse
    {
        return response()->json([
            'message' => 'A request with this idempotency key is still in progress.',
            'code' => 'idempotency_in_progress',
        ], 409);
    }
}
