<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the procurement page lists purchase orders and summary counts', function () {
    $supplier = Supplier::factory()->create(['name' => 'FarmChem Distributors']);
    PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'total' => 100]);
    PurchaseOrder::factory()->received()->create(['total' => 250]);

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertSee('FarmChem Distributors');
    $response->assertSee('Pending orders');
    $response->assertSee('PO #');
    $response->assertViewHas('pendingOrders', 1);
});

test('a purchase order can be created with line items and a computed total', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $supplier = Supplier::factory()->create();

    $response = $this->post(route('procurement.store'), [
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'expected_date' => now()->addWeek()->toDateString(),
        'note' => 'Restock',
        'items' => [
            ['product_id' => $first->id, 'quantity' => 4, 'unit_id' => $first->base_unit_id, 'unit_cost' => 25],
            ['product_id' => $second->id, 'quantity' => 3, 'unit_id' => $second->base_unit_id, 'unit_cost' => 10],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('purchase_orders', [
        'supplier_id' => $supplier->id,
        'status' => 'ordered',
        'total' => 130,
        'note' => 'Restock',
        'created_by' => auth()->id(),
    ]);

    expect(PurchaseOrder::first()->order_date->toDateString())->toEqual(now()->toDateString());

    $this->assertDatabaseHas('purchase_order_items', [
        'product_id' => $first->id,
        'quantity' => 4,
        'unit_cost' => 25,
        'line_total' => 100,
    ]);

    $this->assertDatabaseHas('purchase_order_items', [
        'product_id' => $second->id,
        'quantity' => 3,
        'unit_cost' => 10,
        'line_total' => 30,
    ]);

    expect($first->purchaseOrderItems()->count())->toBe(1);
});

test('creating a purchase order requires at least one item', function () {
    $response = $this->post(route('procurement.store'), [
        'order_date' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors('items');
    $this->assertDatabaseCount('purchase_orders', 0);
});

test('a purchase order rejects a unit the product does not sell in', function () {
    $product = Product::factory()->create();
    $foreignUnit = Unit::where('abbreviation', 'kg')->firstOrFail();

    $response = $this->post(route('procurement.store'), [
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_id' => $foreignUnit->id, 'unit_cost' => 10],
        ],
    ]);

    $response->assertSessionHasErrors('items.0.unit_id');
    $this->assertDatabaseCount('purchase_orders', 0);
});

test('an ordered purchase order can be updated and its items are replaced', function () {
    $order = PurchaseOrder::factory()->create();
    $oldProduct = Product::factory()->create();
    $newProduct = Product::factory()->create();

    $order->items()->create([
        'product_id' => $oldProduct->id,
        'quantity' => 1,
        'unit_id' => $oldProduct->base_unit_id,
        'unit_cost' => 50,
        'line_total' => 50,
    ]);

    $response = $this->put(route('procurement.update', $order), [
        'order_date' => now()->toDateString(),
        'note' => 'Revised',
        'items' => [
            ['product_id' => $newProduct->id, 'quantity' => 2, 'unit_id' => $newProduct->base_unit_id, 'unit_cost' => 15],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'total' => 30, 'note' => 'Revised']);
    $this->assertDatabaseCount('purchase_order_items', 1);
    $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'product_id' => $newProduct->id]);
    $this->assertDatabaseMissing('purchase_order_items', ['purchase_order_id' => $order->id, 'product_id' => $oldProduct->id]);
});

test('receiving an order converts units into stock and writes a linked movement', function () {
    $piece = Unit::where('abbreviation', 'pc')->firstOrFail();
    $dozen = Unit::where('abbreviation', 'dz')->firstOrFail();

    $product = Product::factory()->create(['base_unit_id' => $piece->id, 'stock' => 10]);
    $product->sellingUnits()->create([
        'unit_id' => $dozen->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    $supplier = Supplier::factory()->create();

    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'total' => 60,
    ]);
    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_id' => $dozen->id,
        'unit_cost' => 30,
        'line_total' => 60,
    ]);

    $response = $this->post(route('procurement.receive', $order));

    $response->assertRedirect();
    expect($order->fresh()->status)->toBe('received');
    expect($order->fresh()->received_at)->not->toBeNull();
    expect($product->fresh()->stock)->toEqual(34);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 24,
        'unit_id' => $dozen->id,
        'unit_cost' => 30,
        'supplier_id' => $supplier->id,
        'reason' => 'Purchase',
        'ref_type' => 'procurement',
        'ref_id' => $order->id,
        'user_id' => auth()->id(),
    ]);
});

test('a received order cannot be received a second time', function () {
    $order = PurchaseOrder::factory()->received()->create();
    $order->items()->create([
        'product_id' => Product::factory()->create()->id,
        'quantity' => 1,
        'unit_id' => null,
        'unit_cost' => 10,
        'line_total' => 10,
    ]);

    $response = $this->post(route('procurement.receive', $order));

    $response->assertStatus(422);
    $this->assertDatabaseCount('stock_movements', 0);
});

test('an order can be cancelled and a cancelled order cannot be received', function () {
    $order = PurchaseOrder::factory()->create();

    $cancel = $this->post(route('procurement.cancel', $order));

    $cancel->assertRedirect();
    expect($order->fresh()->status)->toBe('cancelled');

    $receive = $this->post(route('procurement.receive', $order));

    $receive->assertStatus(422);
});

test('a received order cannot be edited or deleted', function () {
    $order = PurchaseOrder::factory()->received()->create();

    $update = $this->put(route('procurement.update', $order), [
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_id' => null, 'unit_cost' => 10],
        ],
    ]);

    $update->assertStatus(422);

    $delete = $this->delete(route('procurement.destroy', $order));

    $delete->assertStatus(422);
    $this->assertDatabaseHas('purchase_orders', ['id' => $order->id]);
});

test('an ordered purchase order can be deleted', function () {
    $order = PurchaseOrder::factory()->create();

    $response = $this->delete(route('procurement.destroy', $order));

    $response->assertRedirect();
    $this->assertDatabaseMissing('purchase_orders', ['id' => $order->id]);
});

test('procurement movements link back to the purchase order on the stock page', function () {
    $product = Product::factory()->create();
    $order = PurchaseOrder::factory()->received()->create();

    StockMovement::factory()->incoming()->create([
        'product_id' => $product->id,
        'quantity' => 5,
        'reason' => 'Purchase',
        'ref_type' => 'procurement',
        'ref_id' => $order->id,
    ]);

    $response = $this->get(route('stock.movements.index'));

    $response->assertOk();
    $response->assertSee('PO #'.$order->id);
});
