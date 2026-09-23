<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = Unit::pluck('id', 'abbreviation');

        foreach (glob(base_path('dbjsons/*_products.json')) as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            foreach ($data['products'] as $item) {
                $product = Product::updateOrCreate(
                    ['name' => $item['product_name']],
                    $this->productData($data['category'], $item, $units)
                );

                $unitId = $units[$this->unitFor($item['product_name'])] ?? null;

                if ($unitId === null) {
                    continue;
                }

                $product->update(['base_unit_id' => $unitId]);

                ProductUnit::updateOrCreate(
                    ['product_id' => $product->id, 'unit_id' => $unitId],
                    ['conversion_to_base' => 1, 'is_base' => true]
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, mixed>  $units
     * @return array<string, mixed>
     */
    private function productData(string $category, array $item, $units): array
    {
        $costPrice = $this->costPrice($item);
        $dealerPrice = $this->nullableDecimal($item['dealers_price_cod'] ?? null);
        $termsPrice = $this->nullableDecimal($item['terms_30_days'] ?? null);

        return [
            'category' => $category,
            'cost_price' => $costPrice,
            'dealers_price_cod' => $dealerPrice,
            'terms_30_days' => $termsPrice,
            'expiry_date' => $item['expiration_date'] ?? null,
            'price' => $dealerPrice ?? $termsPrice ?? $costPrice,
            'stock' => $this->nullableDecimal($item['stock_available'] ?? null) ?? 0,
            'base_unit_id' => $units[$this->unitFor($item['product_name'])] ?? null,
            'note' => $item['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function costPrice(array $item): ?float
    {
        $costPrice = $item['cost_price'] ?? null;

        if (is_int($costPrice) || is_float($costPrice)) {
            return (float) $costPrice;
        }

        return null;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Map the legacy product-name unit to the exact UOM catalog abbreviation.
     */
    private function unitFor(string $name): string
    {
        $lower = strtolower($name);

        if (str_contains($lower, 'gal')) {
            return 'gal';
        }

        if (str_contains($lower, 'ml')) {
            return 'mL';
        }

        if (preg_match('/\d+\s*(g|kg)\b/', $lower)) {
            return 'kg';
        }

        return 'L';
    }
}
