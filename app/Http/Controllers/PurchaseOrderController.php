<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(): View
    {
        return view('procurement.index', [
            'orders' => PurchaseOrder::with(['supplier', 'items.product', 'items.unit'])->latest()->get(),
            'products' => Product::with(['sellingUnits', 'baseUnit'])->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'pendingOrders' => PurchaseOrder::where('status', 'ordered')->count(),
            'spentThisMonth' => PurchaseOrder::where('status', 'received')
                ->where('received_at', '>=', now()->startOfMonth())
                ->sum('total'),
            'lifetimeSpend' => PurchaseOrder::where('status', 'received')->sum('total'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedOrder($request);
        $items = $this->validatedItems($request);

        $order = DB::transaction(function () use ($validated, $items) {
            $order = PurchaseOrder::create([
                'supplier_id' => $validated['supplier_id'] ?? null,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'total' => $this->totalFor($items),
                'note' => $validated['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $order->items()->create($item + ['line_total' => (float) $item['quantity'] * (float) $item['unit_cost']]);
            }

            return $order;
        });

        return back()->with('success', "Purchase order #{$order->id} created.");
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->isOrdered(), 422, 'Only pending orders can be edited.');

        $validated = $this->validatedOrder($request);
        $items = $this->validatedItems($request);

        DB::transaction(function () use ($purchaseOrder, $validated, $items) {
            $purchaseOrder->update([
                'supplier_id' => $validated['supplier_id'] ?? null,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'total' => $this->totalFor($items),
                'note' => $validated['note'] ?? null,
            ]);

            $purchaseOrder->items()->delete();

            foreach ($items as $item) {
                $purchaseOrder->items()->create($item + ['line_total' => (float) $item['quantity'] * (float) $item['unit_cost']]);
            }
        });

        return back()->with('success', "Purchase order #{$purchaseOrder->id} updated.");
    }

    public function receive(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->isOrdered(), 422, 'Only pending orders can be received.');

        DB::transaction(function () use ($purchaseOrder) {
            foreach ($purchaseOrder->items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item->product_id);
                $productUnit = $this->sellingUnit($product, $item->unit_id);
                $baseQty = (float) $item->quantity * (float) $productUnit->conversion_to_base;

                $product->increment('stock', $baseQty);

                $product->stockMovements()->create([
                    'type' => 'in',
                    'quantity' => $baseQty,
                    'unit_id' => $productUnit->unit_id,
                    'unit_cost' => $item->unit_cost,
                    'supplier_id' => $purchaseOrder->supplier_id,
                    'reason' => 'Purchase',
                    'ref_type' => 'procurement',
                    'ref_id' => $purchaseOrder->id,
                    'user_id' => auth()->id(),
                ]);
            }

            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);
        });

        return back()->with('success', "Purchase order #{$purchaseOrder->id} received and added to stock.");
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->isOrdered(), 422, 'Only pending orders can be cancelled.');

        $purchaseOrder->update(['status' => 'cancelled']);

        return back()->with('success', "Purchase order #{$purchaseOrder->id} cancelled.");
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_if($purchaseOrder->isReceived(), 422, 'Received orders cannot be deleted.');

        $purchaseOrder->delete();

        return back()->with('success', 'Purchase order removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedOrder(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return array<int, array{product_id: string, quantity: string, unit_id: string|null, unit_cost: string}>
     */
    private function validatedItems(Request $request): array
    {
        $items = Validator::make($request->all(), [
            'items' => ['present', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ])->validate()['items'];

        foreach ($items as $index => $item) {
            $product = Product::findOrFail($item['product_id']);

            if ($this->sellingUnit($product, $item['unit_id'] ?? null) === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_id" => "{$product->name} does not sell in that unit.",
                ]);
            }
        }

        return array_values($items);
    }

    /**
     * @param  array<int, array<string, string>>  $items
     */
    private function totalFor(array $items): float
    {
        return array_sum(array_map(
            fn (array $item) => (float) $item['quantity'] * (float) $item['unit_cost'],
            $items
        ));
    }

    private function sellingUnit(Product $product, ?int $unitId = null): ?ProductUnit
    {
        if ($unitId !== null) {
            return $product->sellingUnits()->where('unit_id', $unitId)->first();
        }

        return $product->sellingUnits()->where('is_base', true)->first()
            ?? $product->sellingUnits()->first();
    }
}
