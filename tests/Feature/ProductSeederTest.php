<?php

use App\Models\Product;
use App\Models\Unit;
use Database\Seeders\ProductSeeder;

test('the seeder imports products from every category file', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseHas('products', ['name' => 'CROPGIANT (O)', 'category' => 'foliar']);
    $this->assertDatabaseHas('products', ['name' => 'KARATE 500ml', 'category' => 'insecticide']);
    $this->assertDatabaseHas('products', ['name' => 'BAYLUSCIDE WP', 'category' => 'molluscicide']);
    $this->assertDatabaseHas('products', ['name' => 'AGROXONE Liter', 'category' => 'herbicide']);
});

test('the seeder resolves the sale price from the dealer COD price', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseHas('products', [
        'name' => 'FERTI-K',
        'cost_price' => 320,
        'dealers_price_cod' => 340,
        'terms_30_days' => 350,
        'price' => 340,
    ]);
});

test('the seeder stores ambiguous cost prices as null and keeps the note', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseHas('products', [
        'name' => 'VIRTAKO',
        'cost_price' => null,
        'dealers_price_cod' => null,
        'note' => "two handwritten cost values, please verify: '1150' and '1060'",
    ]);

    $this->assertDatabaseHas('products', [
        'name' => 'CRUSHER',
        'cost_price' => null,
    ]);
});

test('the seeder imports products that have no pricing yet', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseHas('products', [
        'name' => 'CONTROL EC',
        'category' => 'molluscicide',
        'cost_price' => null,
        'dealers_price_cod' => null,
        'price' => null,
        'stock' => 0,
    ]);
});

test('the seeder is idempotent', function () {
    $this->seed(ProductSeeder::class);
    $this->seed(ProductSeeder::class);

    expect(Product::where('name', 'FERTI-K')->count())->toBe(1);
    expect(Product::where('name', 'FERTI-K')->first()->sellingUnits()->count())->toBe(1);
});

test('the seeder maps legacy units to uom base units', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseHas('products', [
        'name' => 'KARATE 500ml',
        'base_unit_id' => Unit::where('abbreviation', 'mL')->value('id'),
    ]);
    $this->assertDatabaseHas('products', [
        'name' => 'FERTI-K',
        'base_unit_id' => Unit::where('abbreviation', 'L')->value('id'),
    ]);

    $product = Product::where('name', 'FERTI-K')->first();

    $this->assertDatabaseHas('product_units', [
        'product_id' => $product->id,
        'unit_id' => Unit::where('abbreviation', 'L')->value('id'),
        'is_base' => true,
    ]);
});
