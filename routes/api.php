<?php

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashSessionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UserController;
use App\Models\Location;
use App\Models\PaymentMethod;
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

        Route::get('payment-methods', fn () => ['data' => PaymentMethod::where('active', true)->orderBy('id')->get(['id', 'code', 'name'])]);
        Route::get('locations', fn () => ['data' => Location::activeInOrder()->map->only(['id', 'code', 'name', 'type', 'is_sellable'])->values()]);

        Route::middleware('can:purchases.manage')->group(function () {
            Route::apiResource('purchases', PurchaseController::class)->except('destroy');
            Route::post('purchases/{purchase}/confirm', [PurchaseController::class, 'confirm']);
            Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
            Route::post('purchases/{purchase}/payments', [PurchaseController::class, 'pay']);
        });
        Route::post('purchases/{purchase}/receipts', [PurchaseController::class, 'receive'])->middleware('can:receipts.validate');

        // Caisse et ventes
        Route::middleware('can:cash.operate')->group(function () {
            Route::get('cash-sessions/current', [CashSessionController::class, 'current']);
            Route::get('cash-sessions', [CashSessionController::class, 'index']);
            Route::get('cash-sessions/{cashSession}', [CashSessionController::class, 'show']);
            Route::post('cash-sessions', [CashSessionController::class, 'store']);
            Route::post('cash-sessions/{cashSession}/close', [CashSessionController::class, 'close']);
        });
        Route::middleware('can:sales.create')->group(function () {
            Route::get('sales/config', [SaleController::class, 'config']);
            Route::get('sales/customers', [SaleController::class, 'customers']);
            Route::post('sales', [SaleController::class, 'store']);
        });
        Route::middleware('can:sales.view_own')->group(function () {
            Route::get('sales', [SaleController::class, 'index']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
        });

        Route::apiResource('transfers', TransferController::class)->only(['index', 'show', 'store'])->middleware('can:transfers.manage');

        Route::apiResource('suppliers', SupplierController::class)->except('destroy')->middleware('can:suppliers.manage');
        Route::apiResource('customers', CustomerController::class)->except('destroy')->middleware('can:customers.manage');

        Route::middleware('can:stock.view')->prefix('stock')->group(function () {
            Route::get('balances', [StockController::class, 'balances']);
            Route::get('movements', [StockController::class, 'movements']);
            Route::get('summary', [StockController::class, 'summary']);
        });
        Route::post('stock/adjustments', [StockController::class, 'adjust'])->middleware('can:stock.adjust');

        Route::middleware('can:users.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        });
    });
});
