@php
    /**
     * Analytics sub-navigation. Only the pages the signed-in user is allowed to
     * open are offered, so the menu cannot link to a page that would 403.
     */
    $analyticsSections = collect([
        ['route' => 'analytics.index', 'permission' => 'analytics.view', 'label' => 'Overview'],
        ['route' => 'analytics.sales', 'permission' => 'analytics.sales.view', 'label' => 'Sales'],
        ['route' => 'analytics.gross-profit', 'permission' => 'analytics.profit.view', 'label' => 'Gross profit'],
        ['route' => 'analytics.inventory', 'permission' => 'analytics.inventory.view', 'label' => 'Inventory'],
        ['route' => 'analytics.procurement', 'permission' => 'analytics.procurement.view', 'label' => 'Procurement'],
        ['route' => 'analytics.consignment', 'permission' => 'analytics.consignment.view', 'label' => 'Consignment'],
        ['route' => 'analytics.products', 'permission' => 'analytics.products.view', 'label' => 'Products'],
    ])->filter(fn (array $section) => auth()->user()?->hasPermission($section['permission']));
@endphp

@if ($analyticsSections->isNotEmpty())
    <p class="mt-5 px-2 pb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Analytics</p>

<ul class="space-y-0.5">
        @foreach ($analyticsSections as $section)
            @php
                // Matched by prefix so the CSV exports of a page keep it highlighted.
                $isCurrent = request()->routeIs($section['route'])
                    || request()->routeIs($section['route'].'.*');
                $carried = request()->only([
                    'period', 'date_from', 'date_to', 'category', 'product_id',
                    'supplier_id', 'partner_id', 'customer_id', 'payment_method', 'status',
                ]);
            @endphp
            <li>
                <a
                    href="{{ route($section['route'], $carried) }}"
                    class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm {{ $isCurrent ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-50' }}"
                >
                    {{ $section['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
