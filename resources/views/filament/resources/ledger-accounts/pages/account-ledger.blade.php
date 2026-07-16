<x-filament-panels::page>
    <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Account Type</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ ucfirst($this->getRecord()->type->value) }}</p>
            </div>
            @if ($this->hasPeriodStart())
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Opening Balance (period)</p>
                    <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->openingBalance()) }}</p>
                </div>
            @endif
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Current Balance</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->currentBalance()) }}</p>
            </div>
        </div>
    </div>

    {{ $this->table }}

    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-ta-filters, .fi-pagination { display: none !important; }
        }
    </style>
</x-filament-panels::page>
