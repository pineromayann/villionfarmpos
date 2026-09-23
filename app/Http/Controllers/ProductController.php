<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        return view('inventory.index', [
            'products' => Product::with(['sellingUnits', 'baseUnit'])->orderBy('name')->get(),
            'unitTypes' => UnitType::with('units')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $sellingUnits = $this->validatedSellingUnits($request, $validated['base_unit_id']);

        DB::transaction(function () use ($validated, $sellingUnits) {
            $product = Product::create($validated);

            $this->createSellingUnits($product, $sellingUnits);
        });

        return back()->with('success', 'Product added to inventory.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validated($request, true);
        $sellingUnits = $this->validatedSellingUnits($request, $validated['base_unit_id']);

        DB::transaction(function () use ($product, $validated, $sellingUnits) {
            $product->update($validated);

            $product->sellingUnits()->delete();

            $this->createSellingUnits($product, $sellingUnits);
        });

        return back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Product removed from inventory.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $ignoreStock = false): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:'.implode(',', Product::CATEGORIES)],
            'active_ingredient' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'dealers_price_cod' => ['nullable', 'numeric', 'min:0'],
            'terms_30_days' => ['nullable', 'numeric', 'min:0'],
            'stock' => $ignoreStock
                ? ['nullable', 'numeric', 'min:0']
                : ['required', 'numeric', 'min:0'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['price'] = $validated['price']
            ?? ($validated['dealers_price_cod'] ?? null)
            ?? ($validated['terms_30_days'] ?? null)
            ?? ($validated['cost_price'] ?? null);

        if ($ignoreStock) {
            unset($validated['stock']);
        }

        return $validated;
    }

    /**
     * @return array<int, array{unit_id: string, conversion_to_base: string}>
     */
    private function validatedSellingUnits(Request $request, int $baseUnitId): array
    {
        $validated = $request->validate([
            'selling_units' => ['nullable', 'array'],
            'selling_units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'selling_units.*.conversion_to_base' => ['required', 'numeric', 'gt:0'],
        ])['selling_units'] ?? [];

        $baseType = (int) Unit::findOrFail($baseUnitId)->unit_type_id;

        $unique = [];

        foreach ($validated as $sellingUnit) {
            $unitId = (int) $sellingUnit['unit_id'];

            if ($unitId === $baseUnitId || isset($unique[$unitId])) {
                continue;
            }

            $unique[$unitId] = $sellingUnit;
        }

        foreach ($unique as $sellingUnit) {
            $type = (int) Unit::findOrFail($sellingUnit['unit_id'])->unit_type_id;

            if ($type !== $baseType) {
                throw ValidationException::withMessages([
                    'selling_units.*.unit_id' => 'Selling units must match the base unit type (kg cannot be combined with L).',
                ]);
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, array{unit_id: string, conversion_to_base: string}>  $sellingUnits
     */
    private function createSellingUnits(Product $product, array $sellingUnits): void
    {
        $product->sellingUnits()->create([
            'unit_id' => $product->base_unit_id,
            'conversion_to_base' => 1,
            'is_base' => true,
        ]);

        foreach ($sellingUnits as $sellingUnit) {
            $product->sellingUnits()->create([
                'unit_id' => $sellingUnit['unit_id'],
                'conversion_to_base' => $sellingUnit['conversion_to_base'],
                'is_base' => false,
            ]);
        }
    }
}
