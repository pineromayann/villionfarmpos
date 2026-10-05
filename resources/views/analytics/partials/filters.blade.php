@php
    /**
     * The date range picker shared by every analytics page.
     *
     * One filter definition for all seven pages means a preset behaves the same
     * everywhere, and the custom range fields only appear when they are
     * actually in use. The columns are auto-fitted rather than fixed, so a page
     * offering two filters does not stretch them across the full width.
     *
     * @var \App\Analytics\AnalyticsFilters $filters
     * @var array<string, mixed> $options
     * @var array<int, string> $visible which narrow filters this page offers
     */
    $visible = $visible ?? ['category', 'product', 'customer', 'payment_method'];
    $period = $filters->period;
    $control = 'mt-1 w-full min-w-0 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none';
@endphp

<form method="GET" action="{{ url()->current() }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))]">
        <div>
            <label for="period" class="block text-xs font-medium text-gray-600">Period</label>
            <select id="period" name="period" class="{{ $control }}"
                    onchange="this.form.submit()">
                @foreach (\App\Analytics\AnalyticsPeriod::PRESETS as $key => $label)
                    <option value="{{ $key }}" @selected($period->preset === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if ($period->preset === 'custom')
            <div>
                <label for="date_from" class="block text-xs font-medium text-gray-600">From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $period->from->toDateString() }}"
                       class="{{ $control }}">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-medium text-gray-600">To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $period->to->toDateString() }}"
                       class="{{ $control }}">
            </div>
        @else
            <input type="hidden" name="date_from" value="{{ $period->from->toDateString() }}">
            <input type="hidden" name="date_to" value="{{ $period->to->toDateString() }}">
        @endif

        @if (in_array('category', $visible, true))
            <div>
                <label for="category" class="block text-xs font-medium text-gray-600">Category</label>
                <select id="category" name="category" class="{{ $control }}">
                    <option value="">All categories</option>
                    @foreach ($options['categories'] as $category)
                        <option value="{{ $category }}" @selected($filters->category === $category)>{{ ucfirst($category) }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('product', $visible, true))
            <div>
                <label for="product_id" class="block text-xs font-medium text-gray-600">Product</label>
                <select id="product_id" name="product_id" class="{{ $control }}">
                    <option value="">All products</option>
                    @foreach ($options['products'] as $id => $name)
                        <option value="{{ $id }}" @selected($filters->productId === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('customer', $visible, true))
            <div>
                <label for="customer_id" class="block text-xs font-medium text-gray-600">Customer</label>
                <select id="customer_id" name="customer_id" class="{{ $control }}">
                    <option value="">All customers</option>
                    @foreach ($options['customers'] as $id => $name)
                        <option value="{{ $id }}" @selected($filters->customerId === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('payment_method', $visible, true))
            <div>
                <label for="payment_method" class="block text-xs font-medium text-gray-600">Payment</label>
                <select id="payment_method" name="payment_method" class="{{ $control }}">
                    <option value="">All methods</option>
                    @foreach ($options['paymentMethods'] as $method)
                        <option value="{{ $method }}" @selected($filters->paymentMethod === $method)>
                            {{ str_replace('_', ' ', ucfirst($method)) }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('supplier', $visible, true))
            <div>
                <label for="supplier_id" class="block text-xs font-medium text-gray-600">Supplier</label>
                <select id="supplier_id" name="supplier_id" class="{{ $control }}">
                    <option value="">All suppliers</option>
                    @foreach ($options['suppliers'] as $id => $name)
                        <option value="{{ $id }}" @selected($filters->supplierId === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('status', $visible, true))
            <div>
                <label for="status" class="block text-xs font-medium text-gray-600">Status</label>
                <select id="status" name="status" class="{{ $control }}">
                    <option value="">All statuses</option>
                    @foreach ($options['statuses'] as $status)
                        <option value="{{ $status }}" @selected($filters->status === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('partner', $visible, true))
            <div>
                <label for="partner_id" class="block text-xs font-medium text-gray-600">Partner</label>
                <select id="partner_id" name="partner_id" class="{{ $control }}">
                    <option value="">All partners</option>
                    @foreach ($options['partners'] as $id => $name)
                        <option value="{{ $id }}" @selected($filters->partnerId === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
        <button type="submit" class="w-full rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 sm:w-auto sm:py-1.5">
            Apply filters
        </button>

        @if (! empty($exportRoute))
            <a href="{{ route($exportRoute, array_merge($filters->query(), request()->only(['sort', 'direction']))) }}"
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-center text-sm text-gray-700 hover:bg-gray-50 sm:w-auto sm:py-1.5">
                Download CSV
            </a>
        @endif

        @if ($filters->hasNarrowingFilter())
            <a href="{{ url()->current() }}?period={{ $period->preset }}"
               class="w-full rounded-lg px-3 py-2 text-center text-sm text-gray-600 hover:bg-gray-100 sm:w-auto sm:py-1.5">
                Clear filters
            </a>
        @endif

        <p class="w-full text-xs text-gray-400 sm:ml-auto sm:w-auto">
            Showing {{ $period->label() }}
            @if ($period->preset === 'custom')
                ({{ $period->days() }} days)
            @endif
        </p>
    </div>
</form>
