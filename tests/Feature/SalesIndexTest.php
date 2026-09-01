<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('sales index lists transactions and their totals', function () {
    $sale = Sale::factory()->create([
        'subtotal' => 100,
        'discount' => 10,
        'total' => 90,
        'payment_method' => 'cash',
    ]);

    $response = $this->get(route('sales.index'));

    $response->assertOk();
    $response->assertSee(number_format(90, 2));
});

test('a transaction row opens a modal with its item details', function () {
    $product = Product::factory()->create(['name' => 'Karate 2.5 WG', 'price' => 20, 'unit' => 'kg']);
    $sale = Sale::factory()->create();
    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => 3,
        'unit_price' => 20,
        'line_total' => 60,
    ]);

    $response = $this->get(route('sales.index'));

    $response->assertOk();
    $response->assertSee('Sale #'.$sale->id);
    $response->assertSee('Karate 2.5 WG');
    $response->assertSee('Line total');
    $response->assertSee('@click="open = true"', false);
});
