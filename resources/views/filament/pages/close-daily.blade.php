<x-filament-panels::page>
    <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Expected Cash</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($this->expectedCash()) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Declared Cash</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->summary->declared_cash === null ? '—' : \App\Support\Money::format($this->summary->declared_cash) }}
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Variance</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->summary->variance === null ? '—' : \App\Support\Money::format($this->summary->variance) }}
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                <x-filament::badge :color="match ($this->summary->status->value) {
                    'reconciled' => 'success',
                    'flagged' => 'danger',
                    'submitted' => 'warning',
                    default => 'gray',
                }">
                    {{ ucfirst($this->summary->status->value) }}
                </x-filament::badge>
            </div>
        </div>

        @if ($this->summary->notes)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Notes: {{ $this->summary->notes }}</p>
        @endif
    </div>
</x-filament-panels::page>
