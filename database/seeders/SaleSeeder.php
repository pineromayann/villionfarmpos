<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productId = fn (string $name) => Product::where('name', $name)->value('id');

        $this->recordSale([
            [$productId('KARATE 500ml'), 1],
        ], now()->subMinutes(30));

        $this->recordSale([
            [$productId('MAGNUM Liter'), 2],
            [$productId('BAYLUSCIDE WP'), 1],
            [$productId('FERTI-K'), 2],
        ], now()->subMinutes(15));

        $this->recordSale([
            [$productId('KARATE 500ml'), 3],
            [$productId('AGROXONE Liter'), 2],
        ], now()->subMinutes(5));
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $lines
     */
    private function recordSale(array $lines, Carbon $createdAt): void
    {
        $subtotal = 0;
        $lineData = [];

        foreach ($lines as [$productId, $quantity]) {
            $product = Product::findOrFail($productId);
            $lineTotal = $product->salePrice() * $quantity;
            $subtotal += $lineTotal;

            $lineData[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $product->salePrice(),
                'line_total' => $lineTotal,
            ];
        }

        $sale = Sale::create([
            'customer_id' => null,
            'subtotal' => $subtotal,
            'discount' => 0,
            'total' => $subtotal,
            'payment_method' => 'cash',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($lineData as $line) {
            $sale->items()->create($line);
        }
    }
}
