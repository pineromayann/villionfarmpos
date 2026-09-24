<?php

use App\ConsignmentStockService;
use App\Models\ConsignmentSale;
use App\Models\Sale;
use App\Models\User;

use function Pest\Laravel\post;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('refunding a consigned sale nets the consignment sales page and returns the goods to consigned stock', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['price' => 8]);

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

    $sale = Sale::sole();
    $saleItem = $sale->items()->sole();

    post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $saleItem->id, 'quantity' => 1],
        ],
        'note' => 'buyer returned it',
    ])->assertRedirect(route('refunds.index'));

    $consignmentSale = ConsignmentSale::sole();
    expect((float) $consignmentSale->refunded_quantity)->toEqual(1.0);
    expect($consignmentSale->remainingQuantity())->toEqual(2.0);
    expect($consignmentSale->netLineTotal())->toEqual(16.0);
    expect($consignmentSale->netPayable())->toEqual(10.0);

    expect(ConsignmentStockService::remainingBase($product, $partner))->toEqual(8.0);

    $this->get(route('consignment.sales'))
        ->assertOk()
        ->assertViewHas('totalRetail', 16.0)
        ->assertViewHas('totalPayable', 10.0)
        ->assertViewHas('totalEarned', 6.0)
        ->assertSee('1 returned');

    $this->get(route('refunds.index'))
        ->assertOk()
        ->assertSee('consigned');
});

test('dashboard and sales pages separate earned store revenue from partner payables and refunds', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['price' => 8]);

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

    $sale = Sale::sole();
    $saleItem = $sale->items()->sole();

    post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $saleItem->id, 'quantity' => 1],
        ],
    ])->assertRedirect(route('refunds.index'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('totalRevenue', 24.0)
        ->assertViewHas('refundedTotal', 8.0)
        ->assertViewHas('consignmentPayable', 10.0)
        ->assertViewHas('totalEarned', 6.0);

    $this->get(route('sales.index'))
        ->assertOk()
        ->assertViewHas('totalRevenue', 24.0)
        ->assertViewHas('refundedTotal', 8.0)
        ->assertViewHas('consignmentPayable', 10.0)
        ->assertViewHas('earnedRevenue', 6.0);
});

test('an owned product sale keeps the full amount as earned revenue', function () {
    $product = consignmentProduct(['price' => 8, 'stock' => 48]);

    post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 2],
        ]),
    ])->assertSessionHas('success');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('totalRevenue', 16.0)
        ->assertViewHas('consignmentPayable', 0.0)
        ->assertViewHas('totalEarned', 16.0);
});

test('fully refunding a consigned sale leaves no payable and keeps owned stock untouched', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['price' => 8, 'stock' => 48]);

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

    $sale = Sale::sole();
    $saleItem = $sale->items()->sole();

    post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $saleItem->id, 'quantity' => 3],
        ],
    ])->assertRedirect(route('refunds.index'));

    $consignmentSale = ConsignmentSale::sole();
    expect($consignmentSale->isFullyRefunded())->toBeTrue();
    expect($consignmentSale->netPayable())->toEqual(0.0);

    expect((float) $product->fresh()->stock)->toEqual(48.0);
    expect(ConsignmentStockService::remainingBase($product, $partner))->toEqual(10.0);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('totalRevenue', 24.0)
        ->assertViewHas('refundedTotal', 24.0)
        ->assertViewHas('consignmentPayable', 0.0)
        ->assertViewHas('totalEarned', 0.0);
});
