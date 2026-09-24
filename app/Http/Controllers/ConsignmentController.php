<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\Consignment;
use App\Models\ConsignmentAdjustment;
use App\Models\ConsignmentItem;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\ConsignmentSettlement;
use App\Models\Product;
use App\Models\ProductUnit;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConsignmentController extends Controller
{
    public function receive(): View
    {
        $consignments = Consignment::with(['partner', 'items.product', 'items.unit'])->latest()->get();

        return view('consignment.receive', [
            'consignments' => $consignments,
            'products' => Product::with(['sellingUnits', 'baseUnit'])->orderBy('name')->get(),
            'partners' => ConsignmentPartner::orderBy('name')->get(),
            'partnersCount' => ConsignmentPartner::count(),
            'receivedThisMonth' => (float) ConsignmentItem::whereHas(
                'consignment',
                fn ($query) => $query->where('received_at', '>=', now()->startOfMonth())
            )->sum('line_total'),
            'onHandValue' => $this->onHandValue(),
            'balanceDue' => ConsignmentStockService::totalBalanceDue(),
        ]);
    }

    public function storeReceive(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'exists:consignment_partners,id'],
            'received_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = $this->validatedItems($request);

        $consignment = DB::transaction(function () use ($validated, $items) {
            $consignment = Consignment::create([
                'partner_id' => $validated['partner_id'],
                'received_at' => $validated['received_at'],
                'note' => $validated['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $consignment->items()->create($item + ['line_total' => (float) $item['quantity'] * (float) $item['unit_cost']]);
            }

            return $consignment;
        });

        return back()->with('success', 'Consignment received from '.($consignment->partner?->name ?? 'partner').'.');
    }

    public function stock(): View
    {
        $productIds = ConsignmentItem::query()->distinct()->pluck('product_id');

        $products = Product::with(['sellingUnits', 'baseUnit'])
            ->whereIn('id', $productIds)
            ->orderBy('name')
            ->get();

        $receivedByProduct = ConsignmentItem::query()
            ->join('consignments', 'consignments.id', '=', 'consignment_items.consignment_id')
            ->select(['consignment_items.product_id', 'consignments.partner_id'])
            ->distinct()
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => (int) $row->partner_id)->unique()->values()->all())
            ->all();

        $rows = collect();
        $onHandByProductPartner = [];

        foreach (ConsignmentPartner::orderBy('name')->get() as $partner) {
            $productIds = ConsignmentItem::whereHas(
                'consignment',
                fn ($query) => $query->where('partner_id', $partner->id)
            )->distinct()->pluck('product_id');

            $products = Product::with(['baseUnit', 'sellingUnits'])
                ->whereIn('id', $productIds)
                ->orderBy('name')
                ->get();

            foreach ($products as $product) {
                $remaining = ConsignmentStockService::remainingBase($product, $partner);

                $onHandByProductPartner[(int) $product->id][(int) $partner->id] = $remaining;

                if ($remaining <= 0) {
                    continue;
                }

                $avgCost = ConsignmentStockService::averageCostPerBase($product, $partner);

                $rows->push([
                    'partner' => $partner->name,
                    'product' => $product,
                    'onHand' => $remaining,
                    'avgCost' => $avgCost,
                    'value' => $remaining * $avgCost,
                ]);
            }
        }

        $rows = $rows->sortBy(fn (array $row) => $row['partner'].' '.$row['product']->name)->values();

        return view('consignment.stock', [
            'rows' => $rows,
            'partners' => ConsignmentPartner::orderBy('name')->get(),
            'products' => $products,
            'receivedByProduct' => $receivedByProduct,
            'onHandByProductPartner' => $onHandByProductPartner,
            'adjustments' => ConsignmentAdjustment::with(['partner', 'product', 'creator'])->latest()->limit(50)->get(),
            'onHandValue' => $this->onHandValue(),
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'exists:consignment_partners,id'],
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
                function (string $attribute, mixed $value, Closure $fail) use ($request) {
                    $exists = DB::table('consignment_items')
                        ->join('consignments', 'consignments.id', '=', 'consignment_items.consignment_id')
                        ->where('consignment_items.product_id', $value)
                        ->where('consignments.partner_id', $request->input('partner_id'))
                        ->exists();

                    if (! $exists) {
                        $fail('The selected product was not received from this partner.');
                    }
                },
            ],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'reason' => ['required', 'in:'.implode(',', ConsignmentAdjustment::REASONS)],
            'adjusted_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $partner = ConsignmentPartner::findOrFail($validated['partner_id']);
        $product = Product::findOrFail($validated['product_id']);

        $productUnit = $this->sellingUnit($product, $validated['unit_id'] ?? null);
        $baseQty = (float) $validated['quantity'] * (float) $productUnit->conversion_to_base;
        $remaining = ConsignmentStockService::remainingBase($product, $partner);

        if ($baseQty > $remaining) {
            throw ValidationException::withMessages([
                'quantity' => "Adjustment of {$baseQty} base units exceeds the {$remaining} consigned units of {$product->name} from {$partner->name}.",
            ]);
        }

        $value = $validated['reason'] === 'return'
            ? 0.0
            : $baseQty * ConsignmentStockService::averageCostPerBase($product, $partner);

        ConsignmentAdjustment::create([
            'partner_id' => (int) $validated['partner_id'],
            'product_id' => (int) $validated['product_id'],
            'quantity' => $baseQty,
            'reason' => $validated['reason'],
            'value' => $value,
            'adjusted_at' => $validated['adjusted_at'],
            'note' => $validated['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Consignment adjustment recorded.');
    }

    public function sales(Request $request): View
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'exists:consignment_partners,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $partnerId = isset($validated['partner_id']) ? (int) $validated['partner_id'] : null;

        $rows = ConsignmentSale::with(['partner', 'product', 'customer', 'sale'])
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('sold_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('sold_at', '<=', $to))
            ->latest('sold_at')
            ->get();

        $totalRetail = $rows->sum(fn (ConsignmentSale $sale) => $sale->netLineTotal());
        $totalPayable = $rows->sum(fn (ConsignmentSale $sale) => $sale->netPayable());

        return view('consignment.sales', [
            'rows' => $rows,
            'partners' => ConsignmentPartner::orderBy('name')->get(),
            'totalRetail' => $totalRetail,
            'totalPayable' => $totalPayable,
            'totalEarned' => $totalRetail - $totalPayable,
            'totalReturned' => $rows->sum(fn (ConsignmentSale $sale) => (float) $sale->refunded_line_total),
            'unitsSold' => $rows->sum(fn (ConsignmentSale $sale) => $sale->remainingQuantity()),
            'filters' => $validated,
        ]);
    }

    public function settlement(): View
    {
        $partners = ConsignmentPartner::orderBy('name')->get()->map(fn (ConsignmentPartner $partner) => [
            'partner' => $partner,
            'consignments' => $partner->consignments()->count(),
            'soldPayable' => ConsignmentStockService::netPayable($partner),
            'adjustments' => (float) $partner->adjustments()->sum('value'),
            'settled' => (float) $partner->settlements()->sum('amount'),
            'balanceDue' => ConsignmentStockService::balanceDue($partner),
        ]);

        return view('consignment.settlement', [
            'partners' => $partners,
            'payments' => ConsignmentSettlement::with('partner')->latest()->limit(50)->get(),
            'totalDue' => ConsignmentStockService::totalBalanceDue(),
        ]);
    }

    public function storeSettlement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'exists:consignment_partners,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settled_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        ConsignmentSettlement::create($validated + ['created_by' => auth()->id()]);

        return back()->with('success', 'Settlement recorded.');
    }

    public function history(Request $request): View
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'exists:consignment_partners,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $partnerId = isset($validated['partner_id']) ? (int) $validated['partner_id'] : null;

        $receipts = Consignment::with('partner')->select('id', 'partner_id', 'received_at', 'note')
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('received_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('received_at', '<=', $to))
            ->get()
            ->map(fn (Consignment $consignment) => [
                'date' => $consignment->received_at,
                'type' => 'received',
                'partner' => $consignment->partner?->name ?? '—',
                'label' => 'Consignment received',
                'detail' => $consignment->items()->count().' item(s)',
                'value' => (float) $consignment->items()->sum('line_total'),
            ]);

        $sales = ConsignmentSale::with(['partner', 'product'])
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('sold_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('sold_at', '<=', $to))
            ->get()
            ->map(fn (ConsignmentSale $sale) => [
                'date' => $sale->sold_at,
                'type' => $sale->isFullyRefunded() ? 'refunded' : ($sale->hasRefund() ? 'partially refunded' : 'sold'),
                'partner' => $sale->partner?->name ?? '—',
                'label' => $sale->product->name,
                'detail' => $sale->remainingQuantity().' units at retail ₱'.number_format($sale->netLineTotal(), 2)
                    .($sale->hasRefund() ? ' &middot; '.$sale->refunded_quantity.' returned' : ''),
                'value' => $sale->netPayable(),
            ]);

        $adjustments = ConsignmentAdjustment::with(['partner', 'product'])
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('adjusted_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('adjusted_at', '<=', $to))
            ->get()
            ->map(fn (ConsignmentAdjustment $adjustment) => [
                'date' => $adjustment->adjusted_at,
                'type' => 'adjusted',
                'partner' => $adjustment->partner?->name ?? '—',
                'label' => ucfirst($adjustment->reason),
                'detail' => $adjustment->product->name.' &middot; '.$adjustment->quantity.' units',
                'value' => (float) $adjustment->value,
            ]);

        $settlements = ConsignmentSettlement::with('partner')
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('settled_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('settled_at', '<=', $to))
            ->get()
            ->map(fn (ConsignmentSettlement $settlement) => [
                'date' => $settlement->settled_at,
                'type' => 'settlement',
                'partner' => $settlement->partner?->name ?? '—',
                'label' => 'Payment made',
                'detail' => $settlement->note ?? '',
                'value' => (float) $settlement->amount,
            ]);

        $ledger = collect()
            ->concat($receipts)
            ->concat($sales)
            ->concat($adjustments)
            ->concat($settlements)
            ->sortByDesc('date')
            ->values();

        return view('consignment.history', [
            'ledger' => $ledger,
            'partners' => ConsignmentPartner::orderBy('name')->get(),
            'filters' => $validated,
        ]);
    }

    private function onHandValue(): float
    {
        return ConsignmentPartner::all()->sum(fn (ConsignmentPartner $partner) => ConsignmentStockService::onHandValue($partner));
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

    private function sellingUnit(Product $product, ?int $unitId = null): ?ProductUnit
    {
        if ($unitId !== null) {
            return $product->sellingUnits()->where('unit_id', $unitId)->first();
        }

        return $product->sellingUnits()->where('is_base', true)->first()
            ?? $product->sellingUnits()->first();
    }
}
