<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AdminFulfillmentController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\AdminTelegramController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BakongPaymentController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\DeliverySlotController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Http\Controllers\Api\V1\WishlistController;
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
    // Throttled by both IP and normalized email, closing the distributed password-spray gap
    // that a per-IP limiter alone cannot address.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Unauthenticated: the caller's access token has usually expired by the time they refresh.
    // It still has its own low IP limit, so random-token floods cannot consume unbounded DB CPU.
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');

    // Both are unauthenticated and abuse/enumeration-prone, so they share the auth limiter.
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('email/verification-notification', [AuthController::class, 'sendEmailVerification'])->middleware('throttle:auth');

        // A 6-digit code is checked only against the authenticated user's record. The route is
        // limited by both user and IP; VerifyUserEmail separately burns the code after five
        // failures, which also stops a distributed-IP guessing attack.
        Route::post('email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:verification');
    });
});

// Public catalogue — no authentication, throttled per IP.
Route::middleware('throttle:catalog')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{categoryId}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{productId}', [ProductController::class, 'show']);
    Route::get('products/{productId}/substitutes', [ProductController::class, 'substitutes']);
    Route::get('delivery-slots', [DeliverySlotController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'account.active', 'throttle:authenticated'])->group(function () {
    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::get('addresses/{addressId}', [AddressController::class, 'show']);
    Route::patch('addresses/{addressId}', [AddressController::class, 'update']);
    Route::delete('addresses/{addressId}', [AddressController::class, 'destroy']);

    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'storeItem']);
    Route::patch('cart/items/{cartItemId}', [CartController::class, 'updateItem']);
    Route::delete('cart/items/{cartItemId}', [CartController::class, 'destroyItem']);

    Route::get('wishlist', [WishlistController::class, 'index']);
    Route::post('wishlist/items', [WishlistController::class, 'storeItem']);
    Route::delete('wishlist/items/{wishlistItemId}', [WishlistController::class, 'destroyItem']);

    Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:checkout');

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:checkout');
    Route::get('orders/{orderId}', [OrderController::class, 'show']);
    Route::post('orders/{orderId}/payments/bakong', [BakongPaymentController::class, 'start'])->middleware('throttle:payment');
    Route::post('orders/{orderId}/payments/bakong/verify', [BakongPaymentController::class, 'verify'])->middleware('throttle:payment');
    Route::post('orders/{orderId}/restore-cart', [OrderController::class, 'restoreCart'])->middleware('throttle:checkout');
    Route::post('orders/{orderId}/cancel', [OrderController::class, 'cancel']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// Admin-only catalogue management and order fulfilment.
Route::middleware(['auth:sanctum', 'account.active', 'admin', 'throttle:authenticated'])->group(function () {
    Route::patch('products/{productId}', [ProductController::class, 'update']);
    Route::delete('products/{productId}', [ProductController::class, 'destroy']);

    Route::post('admin/telegram/link', [AdminTelegramController::class, 'link']);
    Route::post('admin/orders/{orderId}/advance', [AdminOrderController::class, 'advance']);
    Route::post('admin/orders/{orderId}/items/{itemId}/pick', [AdminFulfillmentController::class, 'pick']);
    Route::post('admin/orders/{orderId}/items/{itemId}/substitutions', [AdminFulfillmentController::class, 'substitute']);
    Route::post('admin/orders/{orderId}/items/{itemId}/unavailable', [AdminFulfillmentController::class, 'unavailable']);
    Route::post('admin/orders/{orderId}/finalize', [AdminFulfillmentController::class, 'finalize']);
    Route::post('admin/orders/{orderId}/reconcile', [AdminFulfillmentController::class, 'reconcile']);
});

// Telegram webhook — unauthenticated (Telegram cannot send a Bearer token). Authenticity is
// verified via the X-Telegram-Bot-Api-Secret-Token header instead, inside the controller.
Route::post('telegram/webhook', [TelegramWebhookController::class, 'handle'])->middleware('throttle:webhook');
