<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Formato uniforme de la API:
 *   éxito: { "data": ... }
 *   error: { "error": { "code": "...", "message": "...", "details": {...} } }
 */
final class ApiResponse
{
    public static function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
