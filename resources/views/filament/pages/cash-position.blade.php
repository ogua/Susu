<x-filament-panels::page>
    <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total Cash In Branch</p>
        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->totalCash()) }}</p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
