<?php

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashSessionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LossController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
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

        Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view');
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

        Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel'])->middleware('can:sales.cancel');

        // Retours et remboursements
        Route::middleware('can:returns.request')->group(function () {
            Route::get('returns/sale', [SaleReturnController::class, 'saleLookup']);
            Route::get('returns', [SaleReturnController::class, 'index']);
            Route::get('returns/{saleReturn}', [SaleReturnController::class, 'show']);
            Route::post('returns', [SaleReturnController::class, 'store']);
        });
        Route::middleware('can:returns.validate')->group(function () {
            Route::post('returns/{saleReturn}/validate', [SaleReturnController::class, 'validateReturn']);
            Route::post('returns/{saleReturn}/reject', [SaleReturnController::class, 'reject']);
            Route::post('returns/{saleReturn}/refunds', [SaleReturnController::class, 'refund']);
        });

        // Pertes et inventaires
        Route::middleware('can:losses.declare')->group(function () {
            Route::get('losses/reasons', [LossController::class, 'reasons']);
            Route::get('losses', [LossController::class, 'index']);
            Route::post('losses', [LossController::class, 'store']);
        });
        Route::middleware('can:losses.validate')->group(function () {
            Route::post('losses/{loss}/validate', [LossController::class, 'validateLoss']);
            Route::post('losses/{loss}/reject', [LossController::class, 'reject']);
        });
        Route::middleware('can:inventories.manage')->group(function () {
            Route::get('inventories', [InventoryController::class, 'index']);
            Route::get('inventories/{inventory}', [InventoryController::class, 'show']);
            Route::post('inventories', [InventoryController::class, 'store']);
            Route::put('inventories/{inventory}/counts', [InventoryController::class, 'counts']);
            Route::post('inventories/{inventory}/validate', [InventoryController::class, 'validateInventory']);
            Route::post('inventories/{inventory}/cancel', [InventoryController::class, 'cancel']);
        });

        // Dépenses
        Route::middleware('can:expenses.manage')->group(function () {
            Route::get('expense-categories', [ExpenseController::class, 'categories']);
            Route::post('expense-categories', [ExpenseController::class, 'storeCategory']);
            Route::put('expense-categories/{category}', [ExpenseController::class, 'updateCategory']);
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
            Route::put('expenses/{expense}', [ExpenseController::class, 'update']);
            Route::post('expenses/{expense}/attachment', [ExpenseController::class, 'attach']);
            Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment']);
            Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel']);
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
