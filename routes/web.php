<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout',   [AuthController::class, 'logout'])->name('logout');

    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/sale', [PosController::class, 'store'])->name('pos.store');

    Route::resource('inventory', ProductController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['inventory' => 'product']);

    Route::resource('customers', CustomerController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/sales/pdf', [ReportController::class, 'salesPdf'])->name('reports.sales.pdf');
    Route::get('/reports/sales/csv', [ReportController::class, 'salesCsv'])->name('reports.sales.csv');
    Route::get('/reports/inventory/pdf', [ReportController::class, 'inventoryPdf'])->name('reports.inventory.pdf');
    Route::get('/reports/inventory/csv', [ReportController::class, 'inventoryCsv'])->name('reports.inventory.csv');
    Route::get('/reports/customers/pdf', [ReportController::class, 'customersPdf'])->name('reports.customers.pdf');
    Route::get('/reports/customers/csv', [ReportController::class, 'customersCsv'])->name('reports.customers.csv');
});
