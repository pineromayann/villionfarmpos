use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function uomUnit(string $abbreviation): Unit
{
    return Unit::where('abbreviation', $abbreviation)->firstOrFail();
}

function uomProductWithDozen(array $attributes = []): Product
{
    $product = Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 48,
        'price' => 5,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        ...$attributes,
    ]);

    $product->sellingUnits()->create([
        'unit_id' => uomUnit('dz')->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    return $product;
}

test('a product converts a selling unit quantity into its base unit', function () {
    $product = uomProductWithDozen();

    expect($product->convertToBase(3, uomUnit('dz')))->toBe(36.0);
    expect($product->unit)->toBe('pc');
    expect($product->defaultSellingUnit()->is_base)->toBeTrue();
});

test('product conversion rejects a mix of incompatible unit types', function () {
    $product = Product::factory()->create(['base_unit_id' => uomUnit('kg')->id]);

    $product->sellingUnits()->create([
        'unit_id' => uomUnit('L')->id,
        'conversion_to_base' => 1,
        'is_base' => false,
    ]);

    expect(fn () => $product->convertToBase(2, uomUnit('L')))
        ->toThrow(RuntimeException::class, 'Cannot convert');
});

test('a sale in a dozen selling unit converts to base units', function () {
    $product = uomProductWithDozen();
    $dozen = uomUnit('dz');

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'unit_id' => $dozen->id, 'qty' => 2],
        ]),
    ]);

    $response->assertRedirect(route('pos.index'));

    $this->assertDatabaseHas('sale_items', [
        'product_id' => $product->id,
        'quantity' => 24,
        'unit_id' => $dozen->id,
        'unit_price' => 5,
        'line_total' => 120,
    ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 24,
        'unit_id' => $dozen->id,
        'reason' => 'Sale',
    ]);

    expect($product->fresh()->stock)->toEqual(24);
});

test('a sale is rejected when the converted quantity exceeds stock', function () {
    $product = Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 12,
        'price' => 5,
    ]);
    $product->sellingUnits()->create([
        'unit_id' => uomUnit('dz')->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'unit_id' => uomUnit('dz')->id, 'qty' => 2],
        ]),
    ]);

    $response->assertStatus(422);
    expect(Sale::count())->toBe(0);
    expect($product->fresh()->stock)->toEqual(12);
});

test('a sale rejects a unit the product does not sell in', function () {
    $product = uomProductWithDozen();

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'unit_id' => uomUnit('L')->id, 'qty' => 1],
        ]),
    ]);

    $response->assertStatus(422);
    expect(Sale::count())->toBe(0);
});

test('a product form saves extra selling units', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'CROP MASTER',
        'category' => 'foliar',
        'stock' => 10,
        'base_unit_id' => uomUnit('pc')->id,
        'selling_units' => [
            ['unit_id' => uomUnit('dz')->id, 'conversion_to_base' => '12'],
        ],
    ]);

    $response->assertRedirect();

    $product = Product::where('name', 'CROP MASTER')->firstOrFail();

    $this->assertDatabaseHas('product_units', [
        'product_id' => $product->id,
        'unit_id' => uomUnit('pc')->id,
        'is_base' => true,
    ]);
    $this->assertDatabaseHas('product_units', [
        'product_id' => $product->id,
        'unit_id' => uomUnit('dz')->id,
        'is_base' => false,
        'conversion_to_base' => 12,
    ]);
});

test('a product form rejects selling units of a different type', function () {
    $response = $this->post(route('inventory.store'), [
        'name' => 'BAD COMBO',
        'category' => 'foliar',
        'stock' => 5,
        'base_unit_id' => uomUnit('kg')->id,
        'selling_units' => [
            ['unit_id' => uomUnit('L')->id, 'conversion_to_base' => '1'],
        ],
    ]);

    $response->assertSessionHasErrors('selling_units.*.unit_id');
    $this->assertDatabaseMissing('products', ['name' => 'BAD COMBO']);
});

test('updating a product replaces its selling units', function () {
    $product = uomProductWithDozen();
    $pair = uomUnit('pair');

    $response = $this->put(route('inventory.update', $product), [
        'name' => $product->name,
        'category' => $product->category,
        'stock' => 10,
        'base_unit_id' => uomUnit('pc')->id,
        'selling_units' => [
            ['unit_id' => $pair->id, 'conversion_to_base' => '2'],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseMissing('product_units', [
        'product_id' => $product->id,
        'unit_id' => uomUnit('dz')->id,
    ]);
    $this->assertDatabaseHas('product_units', [
        'product_id' => $product->id,
        'unit_id' => uomUnit('pc')->id,
        'is_base' => true,
    ]);
    $this->assertDatabaseHas('product_units', [
        'product_id' => $product->id,
        'unit_id' => $pair->id,
        'is_base' => false,
        'conversion_to_base' => 2,
    ]);
});

test('stock in converts a selling unit quantity into base stock', function () {
    $product = uomProductWithDozen();

    $response = $this->post(route('stock.in.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_id' => uomUnit('dz')->id,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 24,
        'unit_id' => uomUnit('dz')->id,
    ]);
    expect($product->fresh()->stock)->toEqual(72);
});

test('stock out converts a selling unit quantity into base stock', function () {
    $product = uomProductWithDozen();

    $response = $this->post(route('stock.out.store'), [
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_id' => uomUnit('dz')->id,
        'reason' => 'damaged',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 12,
        'unit_id' => uomUnit('dz')->id,
        'reason' => 'damaged',
    ]);
    expect($product->fresh()->stock)->toEqual(36);
});

test('stock out is rejected when the converted quantity exceeds available stock', function () {
    $product = Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 12,
    ]);
    $product->sellingUnits()->create([
        'unit_id' => uomUnit('dz')->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    $response = $this->post(route('stock.out.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_id' => uomUnit('dz')->id,
        'reason' => 'lost',
    ]);

    $response->assertSessionHasErrors('quantity');
    expect($product->fresh()->stock)->toEqual(12);
});