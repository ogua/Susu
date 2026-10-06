@php
    $steps = [
        ['icon' => 'smartphone', 'title' => 'Collect in the field', 'body' => 'Agents record each contribution on the OguaFinance app at the customer\'s stall or door. No signal? It is saved on the phone and queued.'],
        ['icon' => 'sync', 'title' => 'Sync when connected', 'body' => 'Collections upload the moment the phone finds a network. Each record carries its own reference, so nothing is ever posted twice.'],
        ['icon' => 'calendar-check', 'title' => 'Close the day', 'body' => 'Agents remit cash against their collection sheet; supervisors reconcile and close the day. Shortages are visible the same evening, not at month end.'],
        ['icon' => 'file-report', 'title' => 'Report with confidence', 'body' => 'Every posting flows into a double-entry ledger — trial balance, income statement and portfolio reports are always current.'],
    ];
@endphp
<section id="how-it-works" class="bg-fin-card py-20 md:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">How it works</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">From the market stall to the balance sheet, in one flow.</h2>
        </div>

        <ol class="mt-16 grid gap-10 md:grid-cols-2 lg:grid-cols-4 lg:gap-8">
            @foreach ($steps as $index => $step)
                <li class="relative" data-reveal>
                    <div class="flex items-center gap-4">
                        <span class="font-mono text-sm font-medium text-fin-gold-ink">{{ sprintf('%02d', $index + 1) }}</span>
                        <span class="h-px flex-1 bg-fin-line" aria-hidden="true"></span>
                    </div>
                    <div class="mt-5 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-fin-paper-deep text-fin-teal">
                        <x-marketing.icon :name="$step['icon']" class="h-6 w-6" />
                    </div>
                    <h3 class="mt-5 font-display text-xl font-semibold">{{ $step['title'] }}</h3>
                    <p class="mt-2 leading-relaxed text-fin-body">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>
