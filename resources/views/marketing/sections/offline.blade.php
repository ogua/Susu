@php
    $queue = [
        ['time' => '09:14', 'name' => 'Kwame B.', 'amount' => '10.00', 'state' => 'synced'],
        ['time' => '09:31', 'name' => 'Efua M.', 'amount' => '25.00', 'state' => 'synced'],
        ['time' => '10:02', 'name' => 'Yaw O.', 'amount' => '15.00', 'state' => 'queued'],
        ['time' => '10:07', 'name' => 'Adwoa K.', 'amount' => '20.00', 'state' => 'queued'],
    ];
@endphp
<section class="on-dark overflow-hidden bg-fin-ink py-20 text-fin-paper md:py-28">
    <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div data-reveal>
            <p class="eyebrow">Offline-first</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">No network in the market? <span class="italic text-fin-gold">Keep collecting.</span></h2>
            <p class="mt-6 max-w-lg text-lg leading-relaxed text-fin-paper/75">
                The agent app and the Windows desktop app keep working with no connection. Collections, new customers, loan repayments and withdrawals are stored on the device and sent to the server automatically when the network returns — safely, in order, and never twice.
            </p>
            <ul class="mt-8 space-y-3 text-fin-paper/85">
                <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-fin-gold" /> Field agents on Android phones</li>
                <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-fin-gold" /> Branch offices on the Windows desktop app</li>
                <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-fin-gold" /> Head office on any browser, always current</li>
            </ul>
        </div>

        <div data-reveal>
            <div class="mx-auto max-w-sm rounded-[2.2rem] border border-fin-paper/15 bg-fin-ink-soft p-3 shadow-2xl">
                <div class="rounded-[1.7rem] bg-fin-paper p-5 text-fin-ink">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold">Today's collections</p>
                        <span class="flex items-center gap-1.5 rounded-full bg-fin-gold-soft px-2.5 py-1 text-[0.7rem] font-bold text-fin-gold-ink"><x-marketing.icon name="wifi-off" class="h-3.5 w-3.5" /> Offline</span>
                    </div>
                    <ul class="mt-4 divide-y divide-fin-line">
                        @foreach ($queue as $entry)
                            <li class="flex items-center justify-between py-3 text-sm">
                                <div>
                                    <p class="font-semibold">{{ $entry['name'] }}</p>
                                    <p class="font-mono text-xs text-fin-muted">{{ $entry['time'] }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono">GHS {{ $entry['amount'] }}</p>
                                    @if ($entry['state'] === 'synced')
                                        <p class="text-xs font-bold text-fin-teal">Synced</p>
                                    @else
                                        <p class="text-xs font-bold text-fin-gold-ink">Waiting for network</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 rounded-xl bg-fin-paper-deep px-3 py-2.5 text-xs text-fin-body">2 collections will upload automatically when you're back online.</p>
                </div>
            </div>
        </div>
    </div>
</section>
