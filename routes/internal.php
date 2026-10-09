<?php

use App\Http\Controllers\Internal\TusHookController;
use Illuminate\Support\Facades\Route;

Route::middleware('tus.hook')->prefix('internal/tus')->group(function (): void {
    Route::post('/', TusHookController::class);
    Route::post('{hook}', TusHookController::class)
        ->where('hook', 'pre-create|pre-finish|post-finish|post-terminate');
});
