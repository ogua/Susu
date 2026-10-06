@php
    $dailyRate = 20;
    $stampedDays = 23;
@endphp
<section class="ledger-paper relative overflow-hidden">
    <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 pt-14 pb-20 sm:px-6 md:pt-20 lg:grid-cols-12 lg:gap-10 lg:px-8 lg:pb-28">
        <div class="lg:col-span-6 xl:col-span-7">
            <p class="eyebrow">Susu · Savings · Loans · Accounting</p>
            <h1 class="mt-5 font-display text-[2.6rem] leading-[1.05] font-semibold tracking-tight text-balance sm:text-6xl xl:text-7xl">
                Every cedi collected.
                <span class="relative whitespace-nowrap italic text-fin-teal">Every day<svg class="absolute -bottom-2 left-0 h-3 w-full text-fin-gold" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true"><path d="M2 9C40 3 120 2 198 7" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg></span>
                accounted for.
            </h1>
            <p class="mt-7 max-w-xl text-lg leading-relaxed text-fin-body">
                {{ config('marketing.product_name') }} replaces the collector's paper card with a ledger your whole institution can trust — agents record collections on their phones, even with no network, and every pesewa lands in the books the moment they sync.
            </p>
            <div class="mt-9 flex flex-wrap gap-3">
                <a href="{{ route('marketing.demo') }}" class="btn btn-gold">Book a free demo <x-marketing.icon name="arrow-right" class="h-4 w-4" /></a>
                <a href="#pricing" class="btn btn-ghost">See pricing</a>
            </div>
            <ul class="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm font-semibold text-fin-body">
                <li class="flex items-center gap-2"><x-marketing.icon name="wifi-off" class="h-4 w-4 text-fin-teal" /> Works offline</li>
                <li class="flex items-center gap-2"><x-marketing.icon name="momo" class="h-4 w-4 text-fin-teal" /> Mobile Money built in</li>
                <li class="flex items-center gap-2"><x-marketing.icon name="monitor" class="h-4 w-4 text-fin-teal" /> Web · Android · Windows</li>
            </ul>
        </div>

        {{-- The collector's card, rebuilt: 31 daily cells stamped as the month is collected. --}}
        <div class="lg:col-span-6 xl:col-span-5">
            <div class="relative mx-auto max-w-md">
                <div class="absolute -inset-4 -z-0 rotate-3 rounded-[2rem] bg-fin-gold-soft" aria-hidden="true"></div>
                <figure class="relative rounded-3xl border border-fin-line bg-fin-card p-6 shadow-[0_30px_60px_-30px_rgb(28_23_18/0.45)] sm:p-7">
                    <figcaption class="sr-only">Illustration of a digital susu card with 23 of 31 days collected.</figcaption>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-mono text-[0.68rem] tracking-[0.14em] text-fin-muted uppercase">Daily susu card</p>
                            <p class="mt-1 font-display text-xl font-semibold">Ama Serwaa</p>
                            <p class="text-xs text-fin-muted">Makola Market · Agent Kofi A.</p>
                        </div>
                        <span class="shrink-0 whitespace-nowrap rounded-full bg-fin-teal-soft px-3 py-1 text-xs font-bold text-fin-teal">GHS {{ number_format($dailyRate, 2) }}/day</span>
                    </div>

                    <div class="mt-6 grid grid-cols-7 gap-1.5" aria-hidden="true">
                        @for ($day = 1; $day <= 31; $day++)
                            <div class="passbook-cell {{ $day === $stampedDays + 1 ? 'border-solid !border-fin-teal bg-fin-teal-soft' : '' }}">
                                {{ $day }}
                                @if ($day <= $stampedDays)
                                    <span class="passbook-stamp" style="--i: {{ $day }}"><x-marketing.icon name="check" class="h-3 w-3" /></span>
                                @endif
                            </div>
                        @endfor
                    </div>

                    <div class="mt-6 flex items-end justify-between border-t border-dashed border-fin-line pt-5">
                        <div>
                            <p class="text-xs font-semibold text-fin-muted">Collected this cycle</p>
                            <p class="font-mono text-2xl font-medium text-fin-ink">GHS <span data-count-to="{{ $stampedDays * $dailyRate }}">{{ number_format($stampedDays * $dailyRate, 2) }}</span></p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-semibold text-fin-muted">Synced</p>
                            <p class="flex items-center gap-1.5 text-sm font-bold text-fin-teal"><x-marketing.icon name="sync" class="h-4 w-4" /> 2 min ago</p>
                        </div>
                    </div>
                </figure>
            </div>
        </div>
    </div>
    <div class="ledger-rule"></div>
</section>
