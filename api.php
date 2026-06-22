<?php

use App\Http\Controllers\Api\V1\ShopAuthController;
use App\Support\ShopApiRateLimit;
use Illuminate\Support\Facades\Route;

// API v1 - Shop authentication
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [ShopAuthController::class, 'authenticate'])
        ->middleware('throttle:' . ShopApiRateLimit::LIMITER_LOGIN)
        ->name('api.v1.auth.login');

    // Protected by Bearer token
    Route::middleware('shop.token')->group(function () {
        Route::post('/auth/refresh', [ShopAuthController::class, 'refresh'])
            ->middleware('throttle:' . ShopApiRateLimit::LIMITER_AUTHENTICATED)
            ->name('api.v1.auth.refresh');

        Route::post('/auth/logout', [ShopAuthController::class, 'logout'])
            ->middleware('throttle:' . ShopApiRateLimit::LIMITER_AUTHENTICATED)
            ->name('api.v1.auth.logout');

        // Shop data
        Route::get('/shop', [ShopAuthController::class, 'viewShops'])
            ->middleware('throttle:' . ShopApiRateLimit::LIMITER_SHOP_VIEW)
            ->name('api.v1.shop.view');

        // Orders
        Route::post('/orders', [ShopAuthController::class, 'storeOrder'])
            ->middleware('throttle:' . ShopApiRateLimit::LIMITER_ORDERS)
            ->name('api.v1.orders.store');
    });
});
