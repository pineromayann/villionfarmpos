<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Refund;
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

        $refundedTotal = (float) Refund::sum('line_total');
        $refundedToday = (float) Refund::whereDate('created_at', today())->sum('line_total');
        $consignmentPayable = ConsignmentStockService::netPayable();
        $consignmentPayableToday = (float) ConsignmentSale::whereDate('sold_at', today())
            ->get(['payable_amount', 'refunded_payable'])
            ->sum(fn (ConsignmentSale $sale) => (float) $sale->payable_amount - (float) $sale->refunded_payable);

        $totalEarned = $totalRevenue - $refundedTotal - $consignmentPayable;
        $earnedToday = $revenueToday - $refundedToday - $consignmentPayableToday;

        $start = now()->subDays(29)->startOfDay();
        $recentSales = Sale::where('created_at', '>=', $start)->get(['id', 'created_at', 'total']);
        $saleIds = $recentSales->pluck('id');

        $refundBySale = Refund::whereIn('sale_id', $saleIds)
            ->selectRaw('sale_id, SUM(line_total) AS total')
            ->groupBy('sale_id')
            ->pluck('total', 'sale_id');

        $payableBySale = ConsignmentSale::whereIn('sale_id', $saleIds)
            ->get(['sale_id', 'payable_amount', 'refunded_payable'])
            ->groupBy('sale_id')
            ->map(fn ($rows) => $rows->sum(fn (ConsignmentSale $sale) => (float) $sale->payable_amount - (float) $sale->refunded_payable));

        $earnedBySale = $recentSales->mapWithKeys(fn (Sale $sale) => [
            $sale->id => (float) $sale->total
                - (float) ($refundBySale[$sale->id] ?? 0)
                - (float) ($payableBySale[$sale->id] ?? 0),
        ]);

        $last30Days = collect(range(29, 0))->map(function (int $daysAgo) use ($recentSales, $earnedBySale) {
            $date = today()->subDays($daysAgo);
            $daySales = $recentSales->filter(fn (Sale $sale) => $sale->created_at->isSameDay($date));

            return [
                'label' => $date->format('n/j'),
                'total' => (float) $daySales->sum(fn (Sale $sale) => (float) $sale->total),
                'earned' => (float) $daySales->sum(fn (Sale $sale) => (float) $earnedBySale[$sale->id]),
            ];
        });

        $thirtyDaysAgo = now()->subDays(30)->startOfDay();

        $saleItemStats = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.created_at', '>=', $thirtyDaysAgo)
            ->select('sale_items.product_id')
            ->selectRaw('SUM(sale_items.quantity) AS units_sold, SUM(sale_items.line_total) AS revenue')
            ->groupBy('sale_items.product_id')
            ->get()
            ->keyBy('product_id');

        $refundStats = Refund::query()
            ->join('sales', 'refunds.sale_id', '=', 'sales.id')
            ->where('sales.created_at', '>=', $thirtyDaysAgo)
            ->select('refunds.product_id')
            ->selectRaw('SUM(refunds.quantity) AS units_returned, SUM(refunds.line_total) AS returned_revenue')
            ->groupBy('refunds.product_id')
            ->get()
            ->keyBy('product_id');

        $consignmentStats = ConsignmentSale::query()
            ->where('sold_at', '>=', $thirtyDaysAgo)
            ->get(['product_id', 'payable_amount', 'refunded_payable'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum(fn (ConsignmentSale $sale) => (float) $sale->payable_amount - (float) $sale->refunded_payable));

        $topProducts = Product::query()
            ->whereHas('saleItems', fn ($query) => $query->whereHas('sale', fn ($query) => $query->where('created_at', '>=', $thirtyDaysAgo)))
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($saleItemStats, $refundStats, $consignmentStats) {
                $sold = (float) ($saleItemStats->get($product->id)?->units_sold ?? 0);
                $returned = (float) ($refundStats->get($product->id)?->units_returned ?? 0);
                $revenue = (float) ($saleItemStats->get($product->id)?->revenue ?? 0);
                $returnedRevenue = (float) ($refundStats->get($product->id)?->returned_revenue ?? 0);
                $payable = (float) ($consignmentStats->get($product->id) ?? 0);

                return (object) [
                    'name' => $product->name,
                    'units_sold' => $sold - $returned,
                    'revenue' => $revenue - $returnedRevenue - $payable,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

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
            'earnedToday' => $earnedToday,
            'totalEarned' => $totalEarned,
            'consignmentPayable' => $consignmentPayable,
            'refundedTotal' => $refundedTotal,
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
