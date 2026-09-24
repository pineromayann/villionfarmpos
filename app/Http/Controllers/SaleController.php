<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentSale;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function index(): View
    {
        $grossRevenue = (float) Sale::sum('total');
        $refundedTotal = (float) Refund::sum('line_total');
        $consignmentPayable = ConsignmentStockService::netPayable();
        $earnedRevenue = $grossRevenue - $refundedTotal - $consignmentPayable;

        $itemsSold = (float) SaleItem::sum('quantity');
        $unitsReturned = (float) Refund::sum('quantity');
        $saleCount = Sale::count();

        $saleItemStats = SaleItem::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity) AS units_sold, SUM(line_total) AS revenue')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $refundStats = Refund::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity) AS units_returned, SUM(line_total) AS returned_revenue')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $consignmentStats = ConsignmentSale::query()
            ->select('product_id')
            ->selectRaw('SUM(payable_amount - refunded_payable) AS payable')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $topProducts = Product::query()
            ->whereHas('saleItems')
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($saleItemStats, $refundStats, $consignmentStats) {
                $sold = (float) ($saleItemStats->get($product->id)?->units_sold ?? 0);
                $returned = (float) ($refundStats->get($product->id)?->units_returned ?? 0);
                $revenue = (float) ($saleItemStats->get($product->id)?->revenue ?? 0);
                $returnedRevenue = (float) ($refundStats->get($product->id)?->returned_revenue ?? 0);
                $payable = (float) ($consignmentStats->get($product->id)?->payable ?? 0);

                return (object) [
                    'name' => $product->name,
                    'units_sold' => $sold - $returned,
                    'revenue' => $revenue - $returnedRevenue - $payable,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return view('sales.index', [
            'totalRevenue' => $grossRevenue,
            'earnedRevenue' => $earnedRevenue,
            'consignmentPayable' => $consignmentPayable,
            'refundedTotal' => $refundedTotal,
            'itemsSold' => $itemsSold,
            'unitsReturned' => $unitsReturned,
            'avgSale' => $saleCount > 0 ? $grossRevenue / $saleCount : 0,
            'transactions' => Sale::with(['customer', 'items.product', 'refunds'])->latest()->get(),
            'topProducts' => $topProducts,
            'catalogCount' => Product::count(),
        ]);
    }
}
