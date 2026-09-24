<?php

use App\ConsignmentStockService;
use App\Models\ConsignmentSale;
use App\Models\Sale;
use App\Models\User;

use function Pest\Laravel\post;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a consigned sale in a selling unit is stored in base units and converted exactly once', function () {
    $partner = consignmentPartner();
    $product = uomProductWithDozen(['stock' => 48]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 24,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 120,
    ]);

    post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 1, 'unit_id' => uomUnit('dz')->id, 'partner_id' => $partner->id],
        ]),
    ])->assertSessionHas('success');

    expect(ConsignmentStockService::remainingBase($product, $partner))->toEqual(12.0);
    expect((float) $product->fresh()->stock)->toEqual(48.0);

    $this->assertDatabaseHas('consignment_sales', [
        'partner_id' => $partner->id,
        'quantity' => 12,
        'unit_id' => $product->base_unit_id,
    ]);
});

test('two cart lines for the same product cannot consume more than on hand', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 10]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 6, 'partner_id' => $partner->id],
            ['product_id' => $product->id, 'qty' => 6, 'partner_id' => $partner->id],
        ]),
    ])->assertStatus(422);

    expect(Sale::count())->toBe(0);
    expect(ConsignmentSale::count())->toBe(0);
    expect((float) $product->fresh()->stock)->toEqual(10.0);
    expect(ConsignmentStockService::remainingBase($product, $partner))->toEqual(10.0);
});

test('refunding a consigned sale does not inflate owned stock', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 48]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 3, 'partner_id' => $partner->id],
        ]),
    ])->assertSessionHas('success');

    $sale = Sale::orderByDesc('id')->first();
    $saleItem = $sale->items()->first();

    post(route('refunds.store'), [
        'sale_id' => $sale->id,
        'items' => [
            ['sale_item_id' => $saleItem->id, 'quantity' => 3],
        ],
        'note' => 'damaged',
    ])->assertStatus(302);

    expect((float) $product->fresh()->stock)->toEqual(48.0);
    expect(ConsignmentStockService::remainingBase($product, $partner))->toEqual(10.0);

    $consignmentSale = ConsignmentSale::sole();
    expect((float) $consignmentSale->refunded_quantity)->toEqual(3.0);
    expect((float) $consignmentSale->refunded_line_total)->toEqual((float) $consignmentSale->line_total);
    expect($consignmentSale->netPayable())->toEqual(0.0);
    expect($consignmentSale->isFullyRefunded())->toBeTrue();
});
