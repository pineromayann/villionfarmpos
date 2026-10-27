<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
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

test('low stock products are suggested for procurement', function () {
    $low = Product::factory()->lowStock()->create(['name' => 'Fungicide Alpha']);
    Product::factory()->create(['name' => 'Well Stocked Beta', 'stock' => 400]);

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertSee('Suggested to procure');
    $response->assertSee('Fungicide Alpha');
    $response->assertViewHas('suggestions', function (array $suggestions) use ($low): bool {
        return collect($suggestions)->pluck('product_id')->all() === [$low->id];
    });
});

test('a product already on an open purchase order is not suggested again', function () {
    $product = Product::factory()->lowStock()->create();
    $order = PurchaseOrder::factory()->create(['status' => 'ordered']);
    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 20,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 100,
        'line_total' => 2000,
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertViewHas('suggestions', fn (array $suggestions): bool => $suggestions === []);
    $response->assertViewHas('suggestionsOnOrder', 1);
});

test('a received purchase order no longer covers a low stock product', function () {
    $product = Product::factory()->lowStock()->create();
    $order = PurchaseOrder::factory()->received()->create();
    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 20,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 100,
        'line_total' => 2000,
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', fn (array $suggestions): bool => count($suggestions) === 1);
    $response->assertViewHas('suggestionsOnOrder', 0);
});

test('a product with no sales history is topped up to its reorder level', function () {
    $product = Product::factory()->create(['stock' => 4, 'reorder_level' => 10]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions): bool {
        return (float) $suggestions[0]['quantity'] === 6.0;
    });
});

test('the suggested quantity covers the reorder point plus a month of demand', function () {
    $product = Product::factory()->create(['stock' => 2, 'reorder_level' => 10]);

    $sale = Sale::factory()->create();
    $sale->forceFill(['created_at' => now()->subDays(10)])->save();
    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => 90,
        'unit_id' => $product->base_unit_id,
        'unit_price' => 10,
        'line_total' => 900,
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions): bool {
        // 90 units over the 90 day window is 30 a month, so the order covers
        // the reorder point of 10 plus a month of demand on top of the 2 left.
        return (float) $suggestions[0]['quantity'] === 38.0;
    });
});

test('returned units do not count as demand', function () {
    $product = Product::factory()->create(['stock' => 2, 'reorder_level' => 10]);

    $sale = Sale::factory()->create();
    $sale->forceFill(['created_at' => now()->subDays(10)])->save();
    $saleItem = $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => 90,
        'unit_id' => $product->base_unit_id,
        'unit_price' => 10,
        'line_total' => 900,
    ]);

    $sale->refunds()->create([
        'sale_item_id' => $saleItem->id,
        'product_id' => $product->id,
        'quantity' => 90,
        'unit_price' => 10,
        'line_total' => 900,
        'note' => 'Returned',
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions): bool {
        return (float) $suggestions[0]['quantity'] === 8.0;
    });
});

test('a product reorder level overrides the default threshold', function () {
    $generous = Product::factory()->create(['name' => 'Generous Level', 'stock' => 30, 'reorder_level' => 50]);
    Product::factory()->create(['name' => 'Default Level', 'stock' => 30]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions) use ($generous): bool {
        return collect($suggestions)->pluck('product_id')->all() === [$generous->id];
    });
});

test('a suggested product without a selling unit cannot be ordered', function () {
    $product = Product::factory()->create(['stock' => 0, 'reorder_level' => 10]);
    $product->sellingUnits()->delete();

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions): bool {
        return $suggestions[0]['orderable'] === false && $suggestions[0]['unit_id'] === null;
    });
});

test('a suggestion carries the base selling unit and the cost price', function () {
    $product = Product::factory()->create([
        'stock' => 1,
        'reorder_level' => 10,
        'cost_price' => 25,
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestions', function (array $suggestions) use ($product): bool {
        return $suggestions[0]['unit_id'] === $product->base_unit_id
            && (float) $suggestions[0]['unit_cost'] === 25.0
            && (float) $suggestions[0]['estimated_cost'] === 225.0;
    });
});

test('a short list of suggestions stays on the page with no dialog', function () {
    Product::factory()->count(2)->lowStock()->create();

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertDontSee('Show all');
    $response->assertDontSee('All products to procure');
    expect(substr_count($response->getContent(), 'x-model="rows['))->toBe(2);
});

test('a long list of suggestions is previewed inline and opens in a dialog', function () {
    Product::factory()->count(6)->lowStock()->create();

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertSee('Show all 6');
    $response->assertSee('All products to procure (6)');
    // Only the preview rows are rendered on the page itself; the dialog
    // carries every row.
    $preview = \App\Procurement\SuggestionService::PREVIEW_SIZE;
    expect(substr_count($response->getContent(), 'x-model="rows['))->toBe($preview + 6);
});

/**
 * A purchase order with a single dozen line, ready to be received.
 */
function shortDeliveryOrder(int $quantity = 2, int $unitCost = 30, float $reorderLevel = 50): array
{
    $piece = Unit::where('abbreviation', 'pc')->firstOrFail();
    $dozen = Unit::where('abbreviation', 'dz')->firstOrFail();

    $product = Product::factory()->create([
        'base_unit_id' => $piece->id,
        'stock' => 10,
        'reorder_level' => $reorderLevel,
    ]);
    $product->sellingUnits()->create([
        'unit_id' => $dozen->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    $supplier = Supplier::factory()->create();

    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'total' => $quantity * $unitCost,
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit_id' => $dozen->id,
        'unit_cost' => $unitCost,
        'line_total' => $quantity * $unitCost,
    ]);

    return [$order, $item, $product, $supplier];
}

test('a short delivery only adds what actually arrived to stock', function () {
    [$order, $item, $product] = shortDeliveryOrder();

    $response = $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 1],
    ]);

    $response->assertRedirect();

    expect($order->fresh()->status)->toBe('received');
    expect((float) $order->fresh()->received_total)->toEqual(30.0);
    expect($order->fresh()->isPartial())->toBeTrue();

    // One dozen arrived, so twelve base units landed in stock.
    expect($product->fresh()->stock)->toEqual(22);

    $this->assertDatabaseHas('purchase_order_items', [
        'id' => $item->id,
        'received_quantity' => 1,
    ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 12,
        'ref_type' => 'procurement',
        'ref_id' => $order->id,
    ]);
});

test('a line that did not arrive adds no stock and raises a balance order', function () {
    [$order, $item, $product] = shortDeliveryOrder();

    $response = $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 0],
    ]);

    $response->assertRedirect();

    expect($product->fresh()->stock)->toEqual(10);
    $this->assertDatabaseCount('stock_movements', 0);

    $balance = PurchaseOrder::where('parent_order_id', $order->id)->first();

    expect($balance)->not->toBeNull();
    expect($balance->status)->toBe('ordered');
    expect((float) $balance->total)->toEqual(60.0);

    $this->assertDatabaseHas('purchase_order_items', [
        'purchase_order_id' => $balance->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_cost' => 30,
    ]);
});

test('a complete delivery raises no balance order', function () {
    [$order, $item, $product] = shortDeliveryOrder();

    $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 2],
    ]);

    $this->assertDatabaseCount('purchase_orders', 1);
    expect((float) $order->fresh()->received_total)->toEqual(60.0);
    expect($order->fresh()->isPartial())->toBeFalse();
    expect($product->fresh()->stock)->toEqual(34);
});

test('a delivery note is stored on the purchase order and shown on the page', function () {
    [$order, $item] = shortDeliveryOrder();

    $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 1],
        'note' => 'Only one dozen delivered, balance to follow',
    ]);

    $this->assertDatabaseHas('purchase_orders', [
        'id' => $order->id,
        'received_note' => 'Only one dozen delivered, balance to follow',
    ]);

    $this->get(route('procurement.index'))
        ->assertOk()
        ->assertSee('Only one dozen delivered, balance to follow');
});

test('the balance of a short delivery keeps the product on order', function () {
    [$order, $item] = shortDeliveryOrder();

    $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 1],
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('suggestionsOnOrder', 1);
    $response->assertViewHas('suggestions', fn (array $suggestions): bool => $suggestions === []);
});

test('receiving more than the ordered quantity is rejected', function () {
    [$order, $item, $product] = shortDeliveryOrder();

    $response = $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 3],
    ]);

    $response->assertSessionHasErrors("received.{$item->id}");
    expect($product->fresh()->stock)->toEqual(10);
    expect($order->fresh()->status)->toBe('ordered');
});

test('receiving a line that belongs to another order is rejected', function () {
    [$order] = shortDeliveryOrder();

    $other = PurchaseOrder::factory()->create();
    $otherItem = $other->items()->create([
        'product_id' => Product::factory()->create()->id,
        'quantity' => 1,
        'unit_id' => null,
        'unit_cost' => 10,
        'line_total' => 10,
    ]);

    $response = $this->post(route('procurement.receive', $order), [
        'received' => [$otherItem->id => 1],
    ]);

    $response->assertSessionHasErrors("received.{$otherItem->id}");
    expect($order->fresh()->status)->toBe('ordered');
});

test('the receive dialog offers a quantity per line and a delivery note', function () {
    [$order, $item] = shortDeliveryOrder();

    $response = $this->get(route('procurement.index'));

    $response->assertOk();
    $response->assertSee('name="received['.$item->id.']"', false);
    $response->assertSee('x-model.number="received['.$item->id.']"', false);
    $response->assertSee('form="po-receive-form-'.$order->id.'"', false);
    $response->assertSee('id="po-receive-note-'.$order->id.'"', false);
    $response->assertSee('Not delivered');
    $response->assertSee('Delivery note (optional)');
});

test('the procurement page counts only what was received as spend', function () {
    [$order, $item] = shortDeliveryOrder();

    $this->post(route('procurement.receive', $order), [
        'received' => [$item->id => 1],
    ]);

    $response = $this->get(route('procurement.index'));

    $response->assertViewHas('lifetimeSpend', 30.0);
    $response->assertViewHas('spentThisMonth', 30.0);
});
