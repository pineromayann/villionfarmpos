<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
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
        return view('pos.index', [
            'products' => Product::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
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
            'cart.*.qty' => ['required', 'numeric', 'min:0.01'],
        ]);
        $cartValidator->validate();

        $sale = DB::transaction(function () use ($cart, $validated) {
            $subtotal = 0;
            $lines = [];

            foreach ($cart as $line) {
                $product = Product::lockForUpdate()->findOrFail($line['product_id']);

                if ($line['qty'] > $product->stock) {
                    abort(422, "Not enough stock for {$product->name}.");
                }

                $lineTotal = $product->price * $line['qty'];
                $subtotal += $lineTotal;

                $lines[] = [
                    'product' => $product,
                    'quantity' => $line['qty'],
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
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
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                ]);

                $line['product']->decrement('stock', $line['quantity']);
            }

            return $sale;
        });

        return redirect()->route('pos.index')->with('success', "Sale #{$sale->id} completed.");
    }
}
