<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a sale can be completed and decrements stock', function () {
    $product = Product::factory()->create(['price' => 10, 'stock' => 20]);
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

test('a sale is rejected when quantity exceeds stock', function () {
    $product = Product::factory()->create(['price' => 10, 'stock' => 2]);

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

test('a sale requires at least one cart item', function () {
    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([]),
    ]);

    $response->assertSessionHasErrors('cart');
});
