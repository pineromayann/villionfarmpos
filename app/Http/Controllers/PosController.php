<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentItem;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(): View
    {
        $consignedByProduct = [];

        foreach (ConsignmentPartner::orderBy('name')->get() as $partner) {
            $productIds = ConsignmentItem::whereHas(
                'consignment',
                fn ($query) => $query->where('partner_id', $partner->id)
            )->distinct()->pluck('product_id');

            foreach ($productIds as $productId) {
                $product = Product::find($productId);

                if ($product === null) {
                    continue;
                }

                $remaining = ConsignmentStockService::remainingBase($product, $partner);

                if ($remaining <= 0) {
                    continue;
                }

                $consignedByProduct[$productId][] = [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'remaining' => $remaining,
                    'unit' => $product->unit,
                ];
            }
        }

        return view('pos.index', [
            'products' => Product::with(['sellingUnits', 'baseUnit'])->orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
            'consignedByProduct' => $consignedByProduct,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,card,mobile_money'],
            'cart' => ['required', 'json'],
        ]);

        $cart = json_decode($validated['cart'], true);

        $cartValidator = Validator::make(['cart' => $cart], [
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'cart.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'cart.*.qty' => ['required', 'numeric', 'min:0.01'],
            'cart.*.partner_id' => ['nullable', 'integer', 'exists:consignment_partners,id'],
        ]);
        $cartValidator->validate();

        $sale = DB::transaction(function () use ($cart, $validated) {
            $subtotal = 0;
            $lines = [];

            foreach ($cart as $line) {
                $product = Product::lockForUpdate()->findOrFail($line['product_id']);

                $productUnit = $this->sellingUnit($product, $line['unit_id'] ?? null);
                $baseQty = (float) $line['qty'] * (float) $productUnit->conversion_to_base;

                $partner = isset($line['partner_id']) && $line['partner_id'] !== '' && $line['partner_id'] !== null
                    ? ConsignmentPartner::findOrFail((int) $line['partner_id'])
                    : null;

                if ($partner === null) {
                    if ($baseQty > (float) $product->stock) {
                        abort(422, "Not enough stock for {$product->name}.");
                    }
                } elseif ($baseQty > ConsignmentStockService::remainingBase($product, $partner)) {
                    abort(422, "Not enough consigned stock of {$product->name} from {$partner->name}.");
                }

                $lineTotal = $product->salePrice() * $baseQty;
                $subtotal += $lineTotal;

                $lines[] = [
                    'product' => $product,
                    'quantity' => $baseQty,
                    'unit_id' => $productUnit->unit_id,
                    'unit_price' => $product->salePrice(),
                    'line_total' => $lineTotal,
                    'partner' => $partner,
                    'unit_cost' => $partner !== null
                        ? ConsignmentStockService::averageCostPerBase($product, $partner)
                        : null,
                ];
            }

            $discount = $validated['discount'] ?? 0;
            $total = max(0, $subtotal - $discount);

            $sale = Sale::create([
                'customer_id' => $validated['customer_id'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'payment_method' => $validated['payment_method'],
            ]);

            foreach ($lines as $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                ]);

                if ($line['partner'] !== null) {
                    $payable = (float) $line['unit_cost'] * $line['quantity'];

                    ConsignmentSale::create([
                        'partner_id' => $line['partner']->id,
                        'sale_id' => $sale->id,
                        'customer_id' => $validated['customer_id'] ?? null,
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'unit_id' => $line['unit_id'],
                        'unit_price' => $line['unit_price'],
                        'line_total' => $line['line_total'],
                        'unit_cost' => $line['unit_cost'],
                        'payable_amount' => $payable,
                        'sold_at' => now(),
                        'created_by' => auth()->id(),
                    ]);

                    continue;
                }

                $line['product']->decrement('stock', $line['quantity']);

                $line['product']->stockMovements()->create([
                    'type' => 'out',
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'],
                    'reason' => 'Sale',
                    'ref_type' => 'sale',
                    'ref_id' => $sale->id,
                    'user_id' => auth()->id(),
                ]);
            }

            return $sale;
        });

        return redirect()->route('pos.index')->with('success', "Sale #{$sale->id} completed.");
    }

    /**
     * Resolve the selling unit used by a cart line, defaulting to the base unit.
     */
    private function sellingUnit(Product $product, ?int $unitId = null): ProductUnit
    {
        if ($unitId !== null) {
            $productUnit = $product->sellingUnits()->where('unit_id', $unitId)->first();

            if ($productUnit === null) {
                abort(422, "{$product->name} does not sell in that unit.");
            }

            return $productUnit;
        }

        $base = $product->sellingUnits()->where('is_base', true)->first();

        if ($base !== null) {
            return $base;
        }

        return abort(422, "{$product->name} has no selling units configured.");
    }
}
