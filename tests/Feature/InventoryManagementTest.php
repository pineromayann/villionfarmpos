<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('inventory index lists products', function () {
    Product::factory()->create(['name' => 'Actellic 50 EC']);

    $response = $this->get(route('inventory.index'));

    $response->assertOk();
    $response->assertSee('Actellic 50 EC');
});

test('a product can be created', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'Karate 2.5 WG',
        'active_ingredient' => 'Lambda-cyhalothrin',
        'batch_number' => 'KZ-1234',
        'expiry_date' => now()->addYear()->format('Y-m-d'),
        'price' => 18.00,
        'stock' => 6,
        'unit' => 'kg',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('products', ['name' => 'Karate 2.5 WG']);
});

test('creating a product requires a name and numeric price', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => '',
        'price' => 'abc',
        'stock' => 6,
        'unit' => 'L',
    ]);

    $response->assertSessionHasErrors(['name', 'price']);
});

test('a product can be updated', function () {
    $product = Product::factory()->create(['stock' => 5]);

    $response = $this->put(route('inventory.update', $product), [
        'name' => $product->name,
        'active_ingredient' => $product->active_ingredient,
        'batch_number' => $product->batch_number,
        'expiry_date' => $product->expiry_date?->format('Y-m-d'),
        'price' => $product->price,
        'stock' => 50,
        'unit' => $product->unit,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 50]);
});

test('a product can be deleted', function () {
    $product = Product::factory()->create();

    $response = $this->delete(route('inventory.destroy', $product));

    $response->assertRedirect();
    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});

test('low stock products are flagged', function () {
    $lowStock = Product::factory()->lowStock()->create();
    $wellStocked = Product::factory()->create(['stock' => 500]);

    expect($lowStock->isLowStock())->toBeTrue();
    expect($wellStocked->isLowStock())->toBeFalse();
});

test('expiring soon products are flagged', function () {
    $expiringSoon = Product::factory()->expiringSoon()->create();
    $notExpiringSoon = Product::factory()->create(['expiry_date' => now()->addYears(3)]);

    expect($expiringSoon->isExpiringSoon())->toBeTrue();
    expect($notExpiringSoon->isExpiringSoon())->toBeFalse();
});
