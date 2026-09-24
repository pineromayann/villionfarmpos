<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentSale;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RefundController extends Controller
{
    public function index(): View
    {
        return view('refunds.index', [
            'refunds' => Refund::with(['product', 'sale', 'saleItem'])->latest()->get(),
            'sales' => Sale::with('items.product')->latest()->take(50)->get(),
            'totalRefunded' => (float) Refund::sum('line_total'),
            'consignedSaleItemIds' => ConsignmentSale::query()
                ->where('refunded_quantity', '>', 0)
                ->whereNotNull('sale_item_id')
                ->pluck('sale_item_id')
                ->map(fn (int $id) => (int) $id)
                ->all(),
        ]);
    }

    public function saleItems(Sale $sale): JsonResponse
    {
        return response()->json(
            $sale->items()->with('product:id,name')->get()->map(fn (SaleItem $item) => [
                'id' => $item->id,
                'product' => $item->product?->name ?? 'Unknown product',
                'unit' => $item->product?->unit,
                'quantity' => (float) $item->quantity,
                'remaining' => (float) ($item->quantity - $sale->refundedQuantityFor($item)),
                'unit_price' => (float) $item->unit_price,
            ])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $items = collect($validated['items'])
            ->filter(fn (array $line) => (float) ($line['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if ($items === []) {
            throw ValidationException::withMessages([
                'items.quantity' => 'Enter a quantity for at least one item.',
            ]);
        }

        $messages = [];

        DB::transaction(function () use ($items, $validated, &$messages) {
            foreach ($items as $index => $line) {
                $item = SaleItem::with('product')->findOrFail($line['sale_item_id']);
                $sale = $item->sale;
                $returnable = (float) $item->quantity - (float) $sale->refundedQuantityFor($item);

                if ((float) $line['quantity'] > $returnable) {
                    $messages["items.$index.quantity"] = "Only {$returnable} of {$item->product?->name} can be refunded.";
                }
            }

            if ($messages !== []) {
                throw ValidationException::withMessages($messages);
            }

            foreach ($items as $line) {
                $item = SaleItem::with('product')->findOrFail($line['sale_item_id']);
                $product = $item->product;
                $lineTotal = (float) $item->unit_price * (float) $line['quantity'];

                Refund::create([
                    'sale_id' => $item->sale_id,
                    'sale_item_id' => $item->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $item->unit_price,
                    'line_total' => $lineTotal,
                    'note' => $validated['note'] ?? null,
                ]);

                $returnedToConsignment = $this->reverseConsignmentSale($item, (float) $line['quantity']);
                $returnedToOwnedStock = (float) $line['quantity'] - $returnedToConsignment;

                if ($returnedToOwnedStock > 0) {
                    $product->increment('stock', $returnedToOwnedStock);

                    $product->stockMovements()->create([
                        'type' => 'in',
                        'quantity' => $returnedToOwnedStock,
                        'reason' => 'Return',
                        'ref_type' => 'sale',
                        'ref_id' => $item->sale_id,
                        'user_id' => auth()->id(),
                    ]);
                }
            }
        });

        return redirect()->route('refunds.index')->with('success', 'Refund processed.');
    }

    /**
     * Reverse the consignment sale behind a refunded sale item so the returned
     * goods go back on consigned stock and the partner is no longer owed.
     * Consignment sales store quantities in base units, so the refund quantity
     * is converted from the sale item's selling unit.
     * Returns the quantity attributed to consigned stock, in the item's unit.
     */
    private function reverseConsignmentSale(SaleItem $item, float $quantity): float
    {
        $consignment = ConsignmentSale::where('sale_item_id', $item->id)->first();

        if ($consignment === null) {
            return 0.0;
        }

        $refundedBase = ConsignmentStockService::toBaseUnits($item->product, $quantity, $item->unit_id);
        $availableBase = (float) $consignment->quantity - (float) $consignment->refunded_quantity;
        $reversedBase = min($refundedBase, $availableBase);

        if ($reversedBase <= 0) {
            return 0.0;
        }

        $consignment->increment('refunded_quantity', $reversedBase);
        $consignment->increment('refunded_line_total', $reversedBase * (float) $consignment->unit_price);
        $consignment->increment('refunded_payable', $reversedBase * (float) $consignment->unit_cost);

        return $quantity;
    }
}
