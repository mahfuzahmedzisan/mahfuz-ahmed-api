<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

trait RespondsWithApi
{
    protected function apiSuccess(string $message, mixed $data = null, ?int $status = null): JsonResponse
    {
        return $status === null
            ? ApiResponse::success($message, $data)
            : ApiResponse::success($message, $data, $status);
    }

    protected function apiCreated(string $message, mixed $data = null): JsonResponse
    {
        return ApiResponse::created($message, $data);
    }

    protected function apiUnprocessable(string $message, mixed $data = null): JsonResponse
    {
        return ApiResponse::unprocessable($message, $data);
    }
}
