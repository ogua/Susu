<x-filament-panels::page>
    <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Debits</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->totalDebits()) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Credits</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->totalCredits()) }}</p>
            </div>
            <div>
                @if ($this->isBalanced())
                    <span class="inline-flex items-center rounded-full bg-success-100 px-3 py-1 text-sm font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                        Balanced
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-danger-100 px-3 py-1 text-sm font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                        Out of balance
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
