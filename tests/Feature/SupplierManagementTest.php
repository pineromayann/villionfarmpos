<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('suppliers index lists suppliers', function () {
    Supplier::factory()->create(['name' => 'FarmChem Distributors']);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('FarmChem Distributors');
});

test('a supplier can be created', function () {
    $response = $this->post(route('suppliers.store'), [
        'name' => 'FarmChem Distributors',
        'contact_person' => 'Rina',
        'phone' => '0917-000-0000',
        'location' => 'Iloilo',
        'note' => 'Pays in 30 days',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('suppliers', ['name' => 'FarmChem Distributors']);
});

test('creating a supplier requires a name', function () {
    $response = $this->post(route('suppliers.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a supplier can be updated', function () {
    $supplier = Supplier::factory()->create();

    $response = $this->put(route('suppliers.update', $supplier), [
        'name' => 'New Suppliers Inc.',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'New Suppliers Inc.']);
});

test('a supplier can be deleted', function () {
    $supplier = Supplier::factory()->create();

    $response = $this->delete(route('suppliers.destroy', $supplier));

    $response->assertRedirect();
    $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
});

test('a supplier card shows purchase order count and value', function () {
    $supplier = Supplier::factory()->create();
    PurchaseOrder::factory()->count(2)->create(['supplier_id' => $supplier->id, 'total' => 100]);
    PurchaseOrder::factory()->cancelled()->create(['supplier_id' => $supplier->id, 'total' => 500]);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('Purchase orders');
    $response->assertSee('200.00');
    $response->assertDontSee('700.00');
});
