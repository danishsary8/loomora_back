<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Central factory for the API's response envelope.
 *
 * Success: { "data": ..., "meta": { ... } }
 * Failure: { "error": { "code": "...", "message": "...", "details": { ... } } }
 *
 * Every endpoint and the exception handler funnel through here so the contract
 * is guaranteed consistent rather than re-implemented per controller.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        array $meta = [],
        int $status = HttpResponse::HTTP_OK,
    ): JsonResponse {
        $payload = ['data' => self::resolve($data)];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::success($data, $meta, HttpResponse::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status = HttpResponse::HTTP_BAD_REQUEST,
        array $details = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * Unwrap API Resources so they nest inside our envelope's "data" key
     * instead of producing a doubled { data: { data: ... } } shape.
     */
    private static function resolve(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
            return $data->resolve();
        }

        return $data;
    }
}
