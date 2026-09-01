<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function index(): View
    {
        $totalRevenue = (float) Sale::sum('total');
        $itemsSold = (int) SaleItem::sum('quantity');
        $saleCount = Sale::count();

        return view('sales.index', [
            'totalRevenue' => $totalRevenue,
            'itemsSold' => $itemsSold,
            'avgSale' => $saleCount > 0 ? $totalRevenue / $saleCount : 0,
            'transactions' => Sale::with(['customer', 'items.product'])->latest()->get(),
            'topProducts' => Product::query()
                ->withSum('saleItems as units_sold', 'quantity')
                ->withSum('saleItems as revenue', 'line_total')
                ->whereHas('saleItems')
                ->orderByDesc('revenue')
                ->get(),
            'catalogCount' => Product::count(),
        ]);
    }
}
