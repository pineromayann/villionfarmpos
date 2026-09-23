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
    $response->assertSee('All categories');
});

test('a product can be created', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'Karate 2.5 WG',
        'category' => 'insecticide',
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

test('a product can be created with tier pricing', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'FERTI-K',
        'category' => 'foliar',
        'cost_price' => 320,
        'dealers_price_cod' => 340,
        'terms_30_days' => 350,
        'stock' => 0,
        'unit' => 'L',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('products', [
        'name' => 'FERTI-K',
        'category' => 'foliar',
        'cost_price' => 320,
        'dealers_price_cod' => 340,
        'terms_30_days' => 350,
    ]);
});

test('a product requires a valid category', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'Karate 2.5 WG',
        'category' => 'fertilizer',
        'price' => 18,
        'stock' => 6,
        'unit' => 'L',
    ]);

    $response->assertSessionHasErrors('category');
});

test('a product requires a unit from the list', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'Karate 2.5 WG',
        'category' => 'insecticide',
        'price' => 18,
        'stock' => 6,
        'unit' => 'bottle',
    ]);

    $response->assertSessionHasErrors('unit');
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
        'category' => $product->category,
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

test('sale price prefers dealer COD over 30 day terms over cost', function () {
    $product = Product::factory()->make([
        'cost_price' => 320,
        'dealers_price_cod' => 340,
        'terms_30_days' => 350,
        'price' => 500,
    ]);

    expect($product->salePrice())->toBe(340.0);
});

test('sale price falls back to cost when no dealer prices exist', function () {
    $product = Product::factory()->make([
        'cost_price' => 920,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        'price' => null,
    ]);

    expect($product->salePrice())->toBe(920.0);
});

test('sale price falls back to the price column when no pricing tiers exist', function () {
    $product = Product::factory()->make([
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        'price' => 18.5,
    ]);

    expect($product->salePrice())->toBe(18.5);
});
