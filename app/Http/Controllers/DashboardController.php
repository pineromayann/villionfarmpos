<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $revenueToday = Sale::whereDate('created_at', today())->sum('total');
        $totalRevenue = Sale::sum('total');
        $productsInStock = Product::count();
        $salesRecorded = Sale::count();

        $last7Days = collect(range(6, 0))->map(function (int $daysAgo) {
            $date = today()->subDays($daysAgo);

            return [
                'label' => $date->format('D'),
                'total' => (float) Sale::whereDate('created_at', $date)->sum('total'),
            ];
        });

        $lowStock = Product::lowStock()->orderBy('stock')->get();
        $expiringSoon = Product::expiringSoon()->orderBy('expiry_date')->get();

        $recentSales = Sale::with('customer')->latest()->take(3)->get();

        return view('dashboard', [
            'revenueToday' => $revenueToday,
            'totalRevenue' => $totalRevenue,
            'productsInStock' => $productsInStock,
            'salesRecorded' => $salesRecorded,
            'last7Days' => $last7Days,
            'lowStock' => $lowStock,
            'expiringSoon' => $expiringSoon,
            'recentSales' => $recentSales,
            'farmsOnFile' => Customer::count(),
        ]);
    }
}
