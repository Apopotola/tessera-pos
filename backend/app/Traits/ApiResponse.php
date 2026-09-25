<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Standard JSON envelope shared by every Tessera API endpoint.
 * The frontend types this contract as ApiEnvelope<T> / ApiErrorEnvelope.
 */
trait ApiResponse
{
    protected function success(string $message, mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'statusCode' => $statusCode,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * @param  array<string, mixed>|string  $errors
     */
    protected function error(string $message, int $statusCode, array|string $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'statusCode' => $statusCode,
            'errors' => $errors,
        ], $statusCode);
    }
}
