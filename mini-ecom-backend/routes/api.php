<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted at /v1 (see bootstrap/app.php). Paths mirror `docs/openapi.yaml`
| operation-for-operation.
|
*/

Route::prefix('auth')->group(function () {
    // Throttled per IP. Registration is limited primarily as an account-enumeration control.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

    // Unauthenticated: the caller's access token has usually expired by the time they refresh.
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Public catalogue — no authentication, throttled per IP.
Route::middleware('throttle:catalog')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{categoryId}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{productId}', [ProductController::class, 'show']);
    Route::get('products/{productId}/substitutes', [ProductController::class, 'substitutes']);
});

Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function () {
    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::get('addresses/{addressId}', [AddressController::class, 'show']);
    Route::patch('addresses/{addressId}', [AddressController::class, 'update']);
    Route::delete('addresses/{addressId}', [AddressController::class, 'destroy']);
});
