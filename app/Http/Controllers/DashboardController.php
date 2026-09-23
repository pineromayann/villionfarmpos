<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentPartner;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $revenueToday = Sale::whereDate('created_at', today())->sum('total');
        $totalRevenue = Sale::sum('total');
        $productsInStock = Product::count();
        $salesRecorded = Sale::count();

        $last30Days = collect(range(29, 0))->map(function (int $daysAgo) {
            $date = today()->subDays($daysAgo);

            return [
                'label' => $date->format('n/j'),
                'total' => (float) Sale::whereDate('created_at', $date)->sum('total'),
            ];
        });

        $topProducts = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.created_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('products.name as name, SUM(sale_items.line_total) as revenue')
            ->groupBy('products.name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get();

        $paymentBreakdown = Sale::query()
            ->select('payment_method')
            ->selectRaw('SUM(total) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->pluck('total', 'payment_method')
            ->map(fn ($total) => (float) $total)
            ->all();

        $stockValueByCategory = Product::query()
            ->selectRaw('category, SUM(stock * COALESCE(NULLIF(cost_price, 0), price)) as value')
            ->groupBy('category')
            ->orderByDesc('value')
            ->get();

        $consignmentBalances = ConsignmentPartner::orderBy('name')->get()
            ->map(fn (ConsignmentPartner $partner) => [
                'name' => $partner->name,
                'balance' => ConsignmentStockService::balanceDue($partner),
            ])
            ->filter(fn (array $row) => abs($row['balance']) > 0.0001)
            ->values();

        $lowStock = Product::lowStock()->orderBy('stock')->get();
        $expiringSoon = Product::expiringSoon()->orderBy('expiry_date')->get();

        $recentSales = Sale::with('customer')->latest()->take(3)->get();
        $pendingOrders = PurchaseOrder::where('status', 'ordered')->count();
        $spentThisMonth = PurchaseOrder::where('status', 'received')
            ->where('received_at', '>=', now()->startOfMonth())
            ->sum('total');

        $consignmentDue = ConsignmentStockService::totalBalanceDue();
        $consignmentPartners = ConsignmentPartner::count();

        return view('dashboard', [
            'revenueToday' => $revenueToday,
            'totalRevenue' => $totalRevenue,
            'productsInStock' => $productsInStock,
            'salesRecorded' => $salesRecorded,
            'last30Days' => $last30Days,
            'topProducts' => $topProducts,
            'paymentBreakdown' => $paymentBreakdown,
            'stockValueByCategory' => $stockValueByCategory,
            'consignmentBalances' => $consignmentBalances,
            'lowStock' => $lowStock,
            'expiringSoon' => $expiringSoon,
            'recentSales' => $recentSales,
            'farmsOnFile' => Customer::count(),
            'pendingOrders' => $pendingOrders,
            'spentThisMonth' => $spentThisMonth,
            'consignmentDue' => $consignmentDue,
            'consignmentPartners' => $consignmentPartners,
        ]);
    }
}
