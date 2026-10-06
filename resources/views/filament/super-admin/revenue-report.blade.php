<x-filament-panels::page>
    @php($report = $this->report())

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Monthly recurring revenue', \App\Support\Money::format($report['mrr']), $report['paying_companies'].' paying · '.$report['trialing_companies'].' on trial'],
            ['Revenue this month', \App\Support\Money::format(end($report['months'])['total']), 'Subscriptions + licenses'],
            ['Unpaid invoices', \App\Support\Money::format($report['outstanding']), 'Issued, not yet paid'],
            ['Overdue', \App\Support\Money::format($report['overdue']), 'Past their due date'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
