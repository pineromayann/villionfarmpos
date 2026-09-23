<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsignmentPartnerController extends Controller
{
    public function index(): View
    {
        $partners = ConsignmentPartner::withCount('consignments')
            ->withCount('sales')
            ->orderBy('name')
            ->get()
            ->map(function (ConsignmentPartner $partner) {
                $partner->balance_due = ConsignmentStockService::balanceDue($partner);
                $partner->on_hand_value = ConsignmentStockService::onHandValue($partner);

                return $partner;
            });

        return view('consignment.partners', [
            'partners' => $partners,
            'totalDue' => ConsignmentStockService::totalBalanceDue(),
            'onHandValue' => $partners->sum('on_hand_value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ConsignmentPartner::create($this->validated($request));

        return back()->with('success', 'Consignment partner added.');
    }

    public function update(Request $request, ConsignmentPartner $consignmentPartner): RedirectResponse
    {
        $consignmentPartner->update($this->validated($request));

        return back()->with('success', 'Consignment partner updated.');
    }

    public function destroy(ConsignmentPartner $consignmentPartner): RedirectResponse
    {
        $consignmentPartner->delete();

        return back()->with('success', 'Consignment partner removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
