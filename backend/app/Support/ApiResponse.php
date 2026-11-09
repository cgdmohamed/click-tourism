<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    public static function collection(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public static function error(string $message, string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $message,
            'code' => $code,
            'requestId' => request()->header('X-Request-Id', (string) str()->uuid()),
        ], $extra), $status);
    }
}
