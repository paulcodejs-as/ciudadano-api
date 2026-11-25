<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Formato estándar de respuesta de /api/v1:
 * { "success": bool, "message": string, "data": mixed, "meta": object }
 */
trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'OK', array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => (object) $meta,
        ], $status);
    }

    protected function error(string $message, int $status = 400, array $errors = [], array $meta = []): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => (object) $meta,
        ];
        if ($errors) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
