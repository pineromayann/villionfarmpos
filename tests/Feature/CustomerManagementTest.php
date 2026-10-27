<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('customers index lists customers', function () {
    $customer = Customer::factory()->create(['name' => 'Amina Yusuf']);

    $response = $this->get(route('customers.index'));

    $response->assertOk();
    $response->assertSee('Amina Yusuf');
});

test('a customer can be created', function () {
    $response = $this->post(route('customers.store'), [
        'name' => 'Thomas Otieno',
        'farm_name' => 'Otieno Horticulture',
        'phone' => '0712345678',
        'location' => 'Kisumu',
        'crop' => 'Tomatoes',
        'hectares' => 4,
        'notes' => null,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('customers', [
        'name' => 'Thomas Otieno',
        'farm_name' => 'Otieno Horticulture',
    ]);
});

test('creating a customer requires a name', function () {
    $response = $this->post(route('customers.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a customer can be updated', function () {
    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $response = $this->put(route('customers.update', $customer), [
        'name' => 'New Name',
        'farm_name' => $customer->farm_name,
        'phone' => $customer->phone,
        'location' => $customer->location,
        'crop' => $customer->crop,
        'hectares' => $customer->hectares,
        'notes' => $customer->notes,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'New Name']);
});

test('a customer can be deleted', function () {
    $customer = Customer::factory()->create();

    $response = $this->delete(route('customers.destroy', $customer));

    $response->assertRedirect();
    $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
});

test('customers are listed in a table with their details', function () {
    Customer::factory()->create([
        'name' => 'Amina Yusuf',
        'farm_name' => 'Green Valley Farm',
        'phone' => '0712345678',
        'location' => 'Kisumu',
        'crop' => 'Tomatoes',
        'hectares' => 4,
    ]);

    $response = $this->get(route('customers.index'));

    $response->assertOk();
    $response->assertSee('<table', false);
    $response->assertSee('Customer</th>', false);
    $response->assertSee('Farm</th>', false);
    $response->assertSee('Phone</th>', false);
    $response->assertSee('Location</th>', false);
    $response->assertSee('Crop</th>', false);
    $response->assertSee('Hectares</th>', false);
    $response->assertSee('Lifetime spend</th>', false);
    $response->assertSee('Amina Yusuf');
    $response->assertSee('Green Valley Farm');
    $response->assertSee('0712345678');
    $response->assertSee('Kisumu');
    $response->assertSee('Tomatoes');
    $response->assertSee('4');
    $response->assertSee('x-data="customerTable(', false);
});

test('a customer row shows their lifetime spend', function () {
    $customer = Customer::factory()->create();
    Sale::factory()->create(['customer_id' => $customer->id, 'total' => 250]);
    Sale::factory()->create(['customer_id' => $customer->id, 'total' => 150]);

    $response = $this->get(route('customers.index'));

    $response->assertOk();
    $response->assertSee('400.00');
});
