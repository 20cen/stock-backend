<?php

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('can:catalog.view')->group(function () {
            Route::get('categories', [CategoryController::class, 'index']);
            Route::get('articles', [ArticleController::class, 'index']);
            Route::get('articles/barcode/{barcode}', [ArticleController::class, 'byBarcode']);
            Route::get('articles/{article}', [ArticleController::class, 'show']);
        });
        Route::middleware('can:catalog.manage')->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::put('categories/{category}', [CategoryController::class, 'update']);
            Route::post('articles', [ArticleController::class, 'store']);
            Route::put('articles/{article}', [ArticleController::class, 'update']);
        });

        Route::apiResource('suppliers', SupplierController::class)->except('destroy')->middleware('can:suppliers.manage');
        Route::apiResource('customers', CustomerController::class)->except('destroy')->middleware('can:customers.manage');

        Route::middleware('can:stock.view')->prefix('stock')->group(function () {
            Route::get('balances', [StockController::class, 'balances']);
            Route::get('movements', [StockController::class, 'movements']);
        });
        Route::post('stock/adjustments', [StockController::class, 'adjust'])->middleware('can:stock.adjust');

        Route::middleware('can:users.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        });
    });
});
