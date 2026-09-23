<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the pos page renders with category pills', function () {
    Product::factory()->create(['name' => 'KARATE 500ml', 'category' => 'insecticide']);

    $response = $this->get(route('pos.index'));

    $response->assertOk();
    $response->assertSee('Herbicide');
    $response->assertSee('KARATE 500ml');
});

test('a sale can be completed and decrements stock', function () {
    $product = Product::factory()->create([
        'price' => 10,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        'stock' => 20,
    ]);
    $customer = Customer::factory()->create();

    $response = $this->post(route('pos.store'), [
        'customer_id' => $customer->id,
        'discount' => 5,
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 3],
        ]),
    ]);

    $response->assertRedirect(route('pos.index'));

    $this->assertDatabaseHas('sales', [
        'customer_id' => $customer->id,
        'subtotal' => 30,
        'discount' => 5,
        'total' => 25,
        'payment_method' => 'cash',
    ]);

    $this->assertDatabaseHas('sale_items', [
        'product_id' => $product->id,
        'quantity' => 3,
        'unit_price' => 10,
        'line_total' => 30,
    ]);

    expect($product->fresh()->stock)->toEqual(17);
});

test('a sale uses the dealer COD price when available', function () {
    $product = Product::factory()->create([
        'price' => 10,
        'cost_price' => 320,
        'dealers_price_cod' => 340,
        'terms_30_days' => 350,
        'stock' => 20,
    ]);

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 2],
        ]),
    ]);

    $response->assertRedirect(route('pos.index'));

    $this->assertDatabaseHas('sale_items', [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 340,
        'line_total' => 680,
    ]);
});

test('a sale is rejected when quantity exceeds stock', function () {
    $product = Product::factory()->create([
        'price' => 10,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        'stock' => 2,
    ]);

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 5],
        ]),
    ]);

    $response->assertStatus(422);
    expect(Sale::count())->toBe(0);
    expect($product->fresh()->stock)->toEqual(2);
});

test('a sale records an out movement for refunds tracking', function () {
    $product = Product::factory()->create([
        'price' => 10,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        'stock' => 20,
    ]);

    $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 3],
        ]),
    ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 3,
        'reason' => 'Sale',
        'ref_type' => 'sale',
        'ref_id' => Sale::first()->id,
        'user_id' => auth()->id(),
    ]);
});

test('a sale requires at least one cart item', function () {
    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([]),
    ]);

    $response->assertSessionHasErrors('cart');
});
