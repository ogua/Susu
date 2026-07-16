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
                <p class="text-sm text-gray-500 dark:text-gray-400">Net Income</p>
                <p class="text-lg font-semibold {{ $result['netIncome'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ \App\Support\Money::format($result['netIncome']) }}
                </p>
            </div>
        </div>
    </div>

    @foreach ([['Income', $result['incomeRows'], $result['totalIncome']], ['Expenses', $result['expenseRows'], $result['totalExpenses']]] as [$title, $rows, $total])
        <div class="fi-section overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400" colspan="2">{{ $title }}</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $row['code'] }}</td>
                            <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 text-right text-gray-950 dark:text-white">{{ \App\Support\Money::format($row['amount']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No {{ strtolower($title) }} in this period.</td>
                        </tr>
                    @endforelse
                    <tr>
                        <td colspan="2" class="px-4 py-3 font-semibold text-gray-950 dark:text-white">Total {{ $title }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">{{ \App\Support\Money::format($total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endforeach
</x-filament-panels::page>
