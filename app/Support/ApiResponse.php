<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ApiResponse
{
    public static function success(
        string $message,
        mixed $data = null,
        int $status = HttpResponse::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(string $message, mixed $data = null): JsonResponse
    {
        return self::success($message, $data, HttpResponse::HTTP_CREATED);
    }

    public static function error(string $message, int $status, mixed $data = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function unprocessable(string $message, mixed $data = null): JsonResponse
    {
        return self::error($message, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, $data);
    }

    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error($message, HttpResponse::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return self::error($message, HttpResponse::HTTP_FORBIDDEN);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::error($message, HttpResponse::HTTP_NOT_FOUND);
    }

    public static function serverError(string $message = 'Server error.'): JsonResponse
    {
        return self::error($message, HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
