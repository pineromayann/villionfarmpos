<?php

use App\Analytics\AnalyticsFilters;
use App\Analytics\CostBasis;
use App\Analytics\ProfitAnalytics;
use App\Analytics\SalesAnalytics;
use App\Models\ConsignmentSale;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Analytics helpers
|--------------------------------------------------------------------------
|
| Analytics is only as trustworthy as the arithmetic behind it, and the
| arithmetic is where the mistakes actually happen: netting returns twice,
| forgetting that consigned lines are not owned revenue, pricing an unknown
| cost at zero. These helpers build the fixtures each test needs so the test
| itself stays about one behaviour.
|
*/

/**
 * A request carrying the given analytics query string.
 */
function analyticsRequest(array $query = []): Request
{
    return Request::create('/analytics', 'GET', $query);
}

/**
 * Filters for the current month, narrowed by the given query string.
 */
function analyticsFilters(array $query = []): AnalyticsFilters
{
    return AnalyticsFilters::fromRequest(analyticsRequest($query));
}

/**
 * A product with stock and a known selling price.
 */
function analyticsProduct(array $attributes = []): Product
{
    return Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 100,
        'cost_price' => 60,
        'price' => 100,
        ...$attributes,
    ]);
}

/**
 * Record stock received at a dated cost, which is what CostBasis reads.
 */
function stockReceivedAt(Product $product, float $baseQuantity, float $costPerBase): StockMovement
{
    return StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => $baseQuantity,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => $costPerBase,
        'reason' => 'Purchase',
        'created_at' => now()->subDays(30),
    ]);
}

/**
 * A completed owned sale: the product, its line, and the movement that relieved
 * stock. Returns the sale so a test can attach a refund or adjust the totals.
 *
 * @param  array<string, mixed>  $attributes
 */
function ownedSale(Product $product, float $quantity, float $unitPrice, array $attributes = []): Sale
{
    $sale = Sale::factory()->create([
        'subtotal' => $quantity * $unitPrice,
        'discount' => 0,
        'total' => $quantity * $unitPrice,
        'payment_method' => 'cash',
        'created_at' => now(),
        ...$attributes,
    ]);

    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit_id' => $product->base_unit_id,
        'unit_price' => $unitPrice,
        'line_total' => $quantity * $unitPrice,
    ]);

    return $sale;
}

/**
 * A customer return against a sale, in the base unit the sale used.
 */
/**
 * A customer return against a sale, in the base unit the sale used.
 *
 * @param  array<string, mixed>  $attributes
 */
function refundFor(Sale $sale, Product $product, float $quantity, float $unitPrice, array $attributes = []): Refund
{
    return Refund::factory()->create([
        'sale_id' => $sale->id,
        'sale_item_id' => $sale->items()->first()?->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'line_total' => $quantity * $unitPrice,
        'created_at' => now(),
        ...$attributes,
    ]);
}

/**
 * A sale whose second line is consigned rather than owned, mirroring how the
 * POS records a mixed basket: the header total covers both lines.
 *
 * @param  array<string, mixed>  $attributes
 */
function mixedConsignedSale(
    Product $product,
    float $ownedQuantity,
    float $consignedQuantity,
    float $unitPrice,
    array $attributes = [],
): Sale {
    $lineValue = fn (float $quantity): float => $quantity * $unitPrice;

    $sale = Sale::factory()->create([
        'subtotal' => $lineValue($ownedQuantity) + $lineValue($consignedQuantity),
        'discount' => 0,
        'total' => $lineValue($ownedQuantity) + $lineValue($consignedQuantity),
        'payment_method' => 'cash',
        'created_at' => now(),
        ...$attributes,
    ]);

    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => $ownedQuantity,
        'unit_id' => $product->base_unit_id,
        'unit_price' => $unitPrice,
        'line_total' => $lineValue($ownedQuantity),
    ]);

    $consignedItem = $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => $consignedQuantity,
        'unit_id' => $product->base_unit_id,
        'unit_price' => $unitPrice,
        'line_total' => $lineValue($consignedQuantity),
    ]);

    ConsignmentSale::factory()->create([
        'sale_id' => $sale->id,
        'sale_item_id' => $consignedItem->id,
        'product_id' => $product->id,
        'quantity' => $consignedQuantity,
        'unit_price' => $unitPrice,
        'line_total' => $lineValue($consignedQuantity),
        'payable_amount' => $lineValue($consignedQuantity) * 0.8,
        'unit_cost' => 70,
        'sold_at' => $sale->created_at,
    ]);

    return $sale;
}

function salesAnalytics(): SalesAnalytics
{
    return new SalesAnalytics(new CostBasis);
}

function profitAnalytics(): ProfitAnalytics
{
    return new ProfitAnalytics(new CostBasis);
}
