<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $movements = StockMovement::with(['product', 'unit', 'supplier', 'user'])
            ->when($validated['product_id'] ?? null, fn ($query) => $query->where('product_id', $validated['product_id']))
            ->when($validated['type'] ?? null, fn ($query) => $query->where('type', $validated['type']))
            ->when($validated['date_from'] ?? null, fn ($query) => $query->where('created_at', '>=', Carbon::parse($validated['date_from'])->startOfDay()))
            ->when($validated['date_to'] ?? null, fn ($query) => $query->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay()))
            ->latest()
            ->get();

        $totalIn = $movements->where('type', 'in')->sum('quantity');
        $totalOut = $movements->where('type', 'out')->sum('quantity');

        return view('stock.movements', [
            'movements' => $movements,
            'products' => Product::with('sellingUnits')->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'filters' => $validated,
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
            'netChange' => $totalIn - $totalOut,
        ]);
    }

    public function storeIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $productUnit = $this->sellingUnit($product, $validated['unit_id'] ?? null);
        $baseQty = (float) $validated['quantity'] * (float) $productUnit->conversion_to_base;

        DB::transaction(function () use ($product, $productUnit, $baseQty, $validated) {
            $product->increment('stock', $baseQty);

            $product->stockMovements()->create([
                'type' => 'in',
                'quantity' => $baseQty,
                'unit_id' => $productUnit->unit_id,
                'unit_cost' => $validated['unit_cost'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'reason' => $validated['reason'] ?? 'Stock in',
                'user_id' => auth()->id(),
            ]);
        });

        return back()->with('success', "Stock added to {$product->name}.");
    }

    public function storeOut(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'reason' => ['required', 'in:damaged,expired,lost,shrinkage,other'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $productUnit = $this->sellingUnit($product, $validated['unit_id'] ?? null);
        $baseQty = (float) $validated['quantity'] * (float) $productUnit->conversion_to_base;

        if ($baseQty > (float) $product->stock) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$product->stock} {$product->unit} available for {$product->name}.",
            ]);
        }

        DB::transaction(function () use ($product, $productUnit, $baseQty, $validated) {
            $product->decrement('stock', $baseQty);

            $product->stockMovements()->create([
                'type' => 'out',
                'quantity' => $baseQty,
                'unit_id' => $productUnit->unit_id,
                'reason' => $validated['reason'],
                'user_id' => auth()->id(),
            ]);
        });

        return back()->with('success', "Stock removed from {$product->name}.");
    }

    /**
     * Resolve the selling unit used in a movement, defaulting to the product's base unit.
     */
    private function sellingUnit(Product $product, ?int $unitId = null): ProductUnit
    {
        if ($unitId !== null) {
            $productUnit = $product->sellingUnits()->where('unit_id', $unitId)->first();

            if ($productUnit !== null) {
                return $productUnit;
            }

            throw ValidationException::withMessages([
                'unit_id' => "{$product->name} does not sell in that unit.",
            ]);
        }

        $base = $product->sellingUnits()->where('is_base', true)->first();

        if ($base !== null) {
            return $base;
        }

        throw ValidationException::withMessages([
            'unit_id' => "{$product->name} has no selling units configured.",
        ]);
    }
}
