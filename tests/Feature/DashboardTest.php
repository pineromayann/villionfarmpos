<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('dashboard shows correct stat totals', function () {
    Product::factory()->count(3)->create();
    Customer::factory()->count(2)->create();

    Sale::factory()->create(['total' => 50, 'created_at' => now()]);
    Sale::factory()->create(['total' => 25, 'created_at' => now()->subDays(2)]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertViewHas('productsInStock', 3);
    $response->assertViewHas('farmsOnFile', 2);
    $response->assertViewHas('salesRecorded', 2);
    $response->assertViewHas('revenueToday', 50.0);
    $response->assertViewHas('totalRevenue', 75.0);
});

test('dashboard flags low stock and expiring soon products', function () {
    $lowStock = Product::factory()->lowStock()->create();
    $expiringSoon = Product::factory()->expiringSoon()->create();
    Product::factory()->create(['stock' => 500, 'expiry_date' => now()->addYears(3)]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertViewHas('lowStock', fn ($lowStockProducts) => $lowStockProducts->contains($lowStock));
    $response->assertViewHas('expiringSoon', fn ($expiringSoonProducts) => $expiringSoonProducts->contains($expiringSoon));
});

test('dashboard shows pending purchase orders and purchases this month', function () {
    PurchaseOrder::factory()->create(['status' => 'ordered', 'total' => 100]);
    PurchaseOrder::factory()->received()->create(['total' => 40]);
    PurchaseOrder::factory()->received()->create(['total' => 99, 'received_at' => now()->subMonths(2)]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertViewHas('pendingOrders', 1);
    $response->assertViewHas('spentThisMonth', 40.0);
});

test('dashboard exposes owner chart data', function () {
    $product = Product::factory()->create(['name' => 'Martelo', 'stock' => 5, 'cost_price' => 100, 'price' => 120]);
    $cashSale = Sale::factory()->create(['total' => 50, 'payment_method' => 'cash', 'created_at' => now()]);
    $cashSale->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_id' => $product->base_unit_id,
        'unit_price' => 50,
        'line_total' => 50,
    ]);
    Sale::factory()->create(['total' => 30, 'payment_method' => 'mobile_money', 'created_at' => now()]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertViewHas('last30Days', fn ($days) => $days->count() === 30);
    $response->assertViewHas(
        'topProducts',
        fn ($products) => $products->first()->name === 'Martelo' && (float) $products->first()->revenue === 50.0
    );
    $response->assertViewHas(
        'paymentBreakdown',
        fn ($breakdown) => $breakdown['cash'] === 50.0 && $breakdown['mobile_money'] === 30.0
    );
});

test('dashboard shows only five low-stock products with a show-all option', function () {
    Product::factory()->lowStock()->count(7)->create();

    $names = Product::lowStock()->orderBy('stock')->pluck('name')->values();
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertViewHas('lowStock', fn ($items) => $items->count() === 7);
    $response->assertSeeInOrder([$names[4], 'Show all 7 low-stock products', $names[6]]);
});
