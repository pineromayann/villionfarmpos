<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Procurement\SuggestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly SuggestionService $suggestions)
    {
        //
    }

    public function index(): View
    {
        return view('procurement.index', [
            'orders' => PurchaseOrder::with(['supplier', 'items.product', 'items.unit', 'parentOrder', 'balanceOrders'])->latest()->get(),
            'products' => Product::with(['sellingUnits', 'baseUnit'])->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'pendingOrders' => PurchaseOrder::where('status', 'ordered')->count(),
            'spentThisMonth' => (float) PurchaseOrder::where('status', 'received')
                ->where('received_at', '>=', now()->startOfMonth())
                ->sum(DB::raw('COALESCE(received_total, total)')),
            'lifetimeSpend' => (float) PurchaseOrder::where('status', 'received')->sum(DB::raw('COALESCE(received_total, total)')),
            'suggestions' => $this->suggestions->suggestions(),
            'suggestionsOnOrder' => $this->suggestions->coveredByOpenOrders(),
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

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->isOrdered(), 422, 'Only pending orders can be received.');

        $receipts = $this->validatedReceipts($request, $purchaseOrder);
        $note = $request->input('note');

        $balance = DB::transaction(function () use ($purchaseOrder, $receipts, $note) {
            $receivedTotal = 0.0;
            $shortfallLines = [];

            foreach ($purchaseOrder->items as $item) {
                $quantity = $receipts[$item->id] ?? (float) $item->quantity;

                $item->update(['received_quantity' => $quantity]);

                $shortfall = max(0.0, (float) $item->quantity - $quantity);

                if ($shortfall > 0) {
                    $shortfallLines[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $shortfall,
                        'unit_id' => $item->unit_id,
                        'unit_cost' => $item->unit_cost,
                    ];
                }

                if ($quantity <= 0) {
                    continue;
                }

                $product = Product::lockForUpdate()->findOrFail($item->product_id);
                $productUnit = $this->sellingUnit($product, $item->unit_id);
                $baseQty = $quantity * (float) $productUnit->conversion_to_base;

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

                $receivedTotal += $quantity * (float) $item->unit_cost;
            }

            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
                'received_note' => $note !== null ? trim($note) : null,
                'received_total' => round($receivedTotal, 2),
            ]);

            return $this->raiseBalanceOrder($purchaseOrder, $shortfallLines);
        });

        if ($balance !== null) {
            return back()->with('success', "Purchase order #{$purchaseOrder->id} received with a shortfall — the missing items were raised as PO #{$balance->id}.");
        }

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
     * Quantities actually received per order line, keyed by the line id.
     *
     * A request without any quantities receives every line in full.
     *
     * @return array<int, float>
     */
    private function validatedReceipts(Request $request, PurchaseOrder $purchaseOrder): array
    {
        $validated = $request->validate([
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = $purchaseOrder->items->keyBy('id');
        $receipts = [];

        foreach ($validated['received'] ?? [] as $itemId => $quantity) {
            $item = $items->get((int) $itemId);

            if ($item === null) {
                throw ValidationException::withMessages([
                    "received.{$itemId}" => 'That order line does not belong to this purchase order.',
                ]);
            }

            if ((float) $quantity > (float) $item->quantity) {
                throw ValidationException::withMessages([
                    "received.{$itemId}" => "{$item->product->name}: cannot receive more than the {$item->quantity} ordered.",
                ]);
            }

            $receipts[(int) $itemId] = (float) $quantity;
        }

        return $receipts;
    }

    /**
     * Raise a follow-up order for the lines that never arrived.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function raiseBalanceOrder(PurchaseOrder $purchaseOrder, array $lines): ?PurchaseOrder
    {
        if ($lines === []) {
            return null;
        }

        $balance = PurchaseOrder::create([
            'supplier_id' => $purchaseOrder->supplier_id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'expected_date' => $purchaseOrder->expected_date?->isFuture() ? $purchaseOrder->expected_date : null,
            'total' => $this->totalFor($lines),
            'note' => "Balance of PO #{$purchaseOrder->id}: ".$this->shortfallDescription($lines).'.',
            'parent_order_id' => $purchaseOrder->id,
            'created_by' => auth()->id(),
        ]);

        foreach ($lines as $line) {
            $balance->items()->create($line + [
                'line_total' => (float) $line['quantity'] * (float) $line['unit_cost'],
            ]);
        }

        return $balance;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function shortfallDescription(array $lines): string
    {
        $count = count($lines);

        return $count === 1
            ? '1 line was short on the delivery'
            : "{$count} lines were short on the delivery";
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
