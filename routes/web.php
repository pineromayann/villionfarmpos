<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConsignmentController;
use App\Http\Controllers\ConsignmentPartnerController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseOrderController;
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

    Route::resource('consignment-partners', ConsignmentPartnerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('consignment-partners');

    Route::get('/consignment/receive', [ConsignmentController::class, 'receive'])->name('consignment.receive');
    Route::post('/consignment/receive', [ConsignmentController::class, 'storeReceive'])->name('consignment.receive.store');
    Route::get('/consignment/stock', [ConsignmentController::class, 'stock'])->name('consignment.stock');
    Route::post('/consignment/stock/adjust', [ConsignmentController::class, 'storeAdjustment'])->name('consignment.adjustments.store');
    Route::get('/consignment/sales', [ConsignmentController::class, 'sales'])->name('consignment.sales');
    Route::get('/consignment/settlement', [ConsignmentController::class, 'settlement'])->name('consignment.settlement');
    Route::post('/consignment/settlement', [ConsignmentController::class, 'storeSettlement'])->name('consignment.settlement.store');
    Route::get('/consignment/history', [ConsignmentController::class, 'history'])->name('consignment.history');

    Route::get('/stock', [StockController::class, 'index'])->name('stock.movements.index');
    Route::post('/stock/in', [StockController::class, 'storeIn'])->name('stock.in.store');
    Route::post('/stock/out', [StockController::class, 'storeOut'])->name('stock.out.store');

    Route::get('/procurement', [PurchaseOrderController::class, 'index'])->name('procurement.index');
    Route::post('/procurement/orders', [PurchaseOrderController::class, 'store'])->name('procurement.store');
    Route::put('/procurement/orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('procurement.update');
    Route::post('/procurement/orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('procurement.receive');
    Route::post('/procurement/orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('procurement.cancel');
    Route::delete('/procurement/orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('procurement.destroy');

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
    Route::get('/reports/purchases/pdf', [ReportController::class, 'purchasesPdf'])->name('reports.purchases.pdf');
    Route::get('/reports/purchases/csv', [ReportController::class, 'purchasesCsv'])->name('reports.purchases.csv');
    Route::get('/reports/consignment/pdf', [ReportController::class, 'consignmentPdf'])->name('reports.consignment.pdf');
    Route::get('/reports/consignment/csv', [ReportController::class, 'consignmentCsv'])->name('reports.consignment.csv');
});
