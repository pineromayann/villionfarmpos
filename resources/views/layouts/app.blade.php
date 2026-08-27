<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') &mdash; VillonFarm POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased" x-data="{ sidebarOpen: false }">
    <div class="flex min-h-screen">
        <div
            x-show="sidebarOpen"
            x-cloak
            @click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-black/30 md:hidden"
        ></div>

        <aside
            class="fixed inset-y-0 left-0 z-40 w-60 shrink-0 -translate-x-full transform border-r border-gray-200 bg-white transition-transform duration-200 md:static md:translate-x-0"
            :class="sidebarOpen && '!translate-x-0'"
        >
            <div class="flex items-center gap-3 px-5 py-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100">
                    <x-icon name="sprout" class="h-5 w-5 text-emerald-700" />
                </div>
                <div>
                    <p class="text-sm font-semibold leading-tight text-gray-900">VillonFarm</p>
                    <p class="text-xs leading-tight text-gray-500">Insecticide POS</p>
                </div>
            </div>

            <nav class="px-3 py-2">
                <p class="px-2 pb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Workspace</p>

                @php
                    $navItems = [
                        ['route' => 'dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'],
                        ['route' => 'pos.index', 'icon' => 'pos', 'label' => 'Point of Sale'],
                        ['route' => 'inventory.index', 'icon' => 'inventory', 'label' => 'Inventory'],
                        ['route' => 'sales.index', 'icon' => 'sales', 'label' => 'Sales'],
                        ['route' => 'customers.index', 'icon' => 'customers', 'label' => 'Customers'],
                        ['route' => 'reports.index', 'icon' => 'report', 'label' => 'Reports'],
                    ];
                @endphp

                <ul class="space-y-0.5">
                    @foreach ($navItems as $item)
                        <li>
                            <a
                                href="{{ route($item['route']) }}"
                                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm {{ request()->routeIs($item['route']) ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-50' }}"
                            >
                                <x-icon :name="$item['icon']" class="h-[18px] w-[18px]" />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 md:px-6">
                <button @click="sidebarOpen = !sidebarOpen" class="text-gray-500 hover:text-gray-700 md:hidden">
                    <x-icon name="sidebar" class="h-5 w-5" />
                </button>
                <x-icon name="sidebar" class="hidden h-5 w-5 text-gray-400 md:block" />
                <span class="text-sm text-gray-500">Farm Management &middot; Insecticide POS</span>
            </header>

            <main class="flex-1 p-4 md:p-8">
                <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">@yield('heading')</h1>
                        <p class="mt-1 text-sm text-gray-500">@yield('subheading')</p>
                    </div>
                    <div>@yield('actions')</div>
                </div>

                @if (session('success'))
                    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        {{ session('success') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
