<?php

namespace App\Http\Controllers;

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
        ]);
    }

    public function saleItems(Sale $sale): JsonResponse
    {
        return response()->json(
            $sale->items()->with('product:id,name,unit')->get()->map(fn (SaleItem $item) => [
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
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $messages = [];

        DB::transaction(function () use ($validated, &$messages) {
            foreach ($validated['items'] as $index => $line) {
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

            foreach ($validated['items'] as $line) {
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

                $product->increment('stock', $line['quantity']);

                $product->stockMovements()->create([
                    'type' => 'in',
                    'quantity' => $line['quantity'],
                    'reason' => 'Return',
                    'ref_type' => 'sale',
                    'ref_id' => $item->sale_id,
                    'user_id' => auth()->id(),
                ]);
            }
        });

        return redirect()->route('refunds.index')->with('success', 'Refund processed.');
    }
}
