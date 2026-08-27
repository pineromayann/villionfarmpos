<?php

use App\Models\Customer;
use App\Models\Product;
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
