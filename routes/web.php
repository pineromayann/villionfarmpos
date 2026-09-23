<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/sale', [PosController::class, 'store'])->name('pos.store');

    Route::resource('inventory', ProductController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['inventory' => 'product']);

    Route::resource('customers', CustomerController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('suppliers', SupplierController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::get('/stock', [StockController::class, 'index'])->name('stock.movements.index');
    Route::post('/stock/in', [StockController::class, 'storeIn'])->name('stock.in.store');
    Route::post('/stock/out', [StockController::class, 'storeOut'])->name('stock.out.store');

    Route::get('/refunds', [RefundController::class, 'index'])->name('refunds.index');
    Route::get('/refunds/sales/{sale}/items', [RefundController::class, 'saleItems'])->name('refunds.sale-items');
    Route::post('/refunds', [RefundController::class, 'store'])->name('refunds.store');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/sales/pdf', [ReportController::class, 'salesPdf'])->name('reports.sales.pdf');
    Route::get('/reports/sales/csv', [ReportController::class, 'salesCsv'])->name('reports.sales.csv');
    Route::get('/reports/inventory/pdf', [ReportController::class, 'inventoryPdf'])->name('reports.inventory.pdf');
    Route::get('/reports/inventory/csv', [ReportController::class, 'inventoryCsv'])->name('reports.inventory.csv');
    Route::get('/reports/customers/pdf', [ReportController::class, 'customersPdf'])->name('reports.customers.pdf');
    Route::get('/reports/customers/csv', [ReportController::class, 'customersCsv'])->name('reports.customers.csv');
    Route::get('/reports/stock/pdf', [ReportController::class, 'stockPdf'])->name('reports.stock.pdf');
    Route::get('/reports/stock/csv', [ReportController::class, 'stockCsv'])->name('reports.stock.csv');
});
