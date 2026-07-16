<x-filament-panels::page>
    @php($result = $this->report())

    <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400" for="from">From</label>
                <input id="from" type="date" wire:model.live="from"
                       class="block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
            </div>
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400" for="until">Until</label>
                <input id="until" type="date" wire:model.live="until"
                       class="block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
            </div>
            <div class="ml-auto text-right">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Collections</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($result['totals']['collections_total']) }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Commission</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($result['totals']['commission_total']) }}</p>
            </div>
        </div>
    </div>

    <div class="fi-section overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                    <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">Agent</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Days Worked</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Collections</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Collections Total</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Variance</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Unreconciled Days</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Commission</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($result['rows'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $row['agent'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ $row['days_worked'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ $row['collections_count'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ \App\Support\Money::format($row['collections_total']) }}</td>
                        <td class="px-4 py-3 text-right {{ $row['variance_total'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ \App\Support\Money::format($row['variance_total']) }}</td>
                        <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ $row['unreconciled_days'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ \App\Support\Money::format($row['commission_total']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No agent activity in this period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
