@php
    $limitLabel = fn (?int $limit, string $noun): string => $limit === null ? "Unlimited {$noun}" : number_format($limit).' '.\Illuminate\Support\Str::plural(rtrim($noun, 's'), $limit);
    $featuredIndex = $plans->count() >= 3 ? 1 : null;
@endphp
<section id="pricing" class="ledger-paper py-20 md:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="eyebrow">Pricing</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">Simple plans, priced in cedis.</h2>
            <p class="mt-5 text-lg text-fin-body">Every plan includes the web app, agent and customer mobile apps, SMS integration and Mobile Money payments.</p>
        </div>

        @if ($plans->isEmpty())
            <div class="mx-auto mt-14 max-w-2xl rounded-3xl border border-fin-line bg-fin-card p-8 text-center md:p-12" data-reveal>
                <h3 class="font-display text-2xl font-semibold">Talk to us about pricing</h3>
                <p class="mt-3 text-fin-body">Pricing depends on your number of branches, staff and customers. Tell us about your institution and we'll send a quote with your demo.</p>
                <a href="{{ route('marketing.demo') }}" class="btn btn-ink mt-7">Talk to sales <x-marketing.icon name="arrow-right" class="h-4 w-4" /></a>
            </div>
        @else
            <div @class([
                'mx-auto mt-14 grid gap-6',
                'max-w-md' => $plans->count() === 1,
                'max-w-4xl md:grid-cols-2' => $plans->count() === 2,
                'md:grid-cols-2 lg:grid-cols-3' => $plans->count() >= 3,
            ])>
                @foreach ($plans as $index => $plan)
                    @php($isFeatured = $index === $featuredIndex)
                    <article @class([
                        'relative flex flex-col rounded-3xl border p-8',
                        'on-dark border-fin-ink bg-fin-ink text-fin-paper shadow-2xl lg:-my-4' => $isFeatured,
                        'border-fin-line bg-fin-card' => ! $isFeatured,
                    ]) data-reveal>
                        @if ($isFeatured)
                            <span class="absolute -top-3 left-8 rounded-full bg-fin-gold px-3 py-1 text-xs font-bold text-fin-ink">Most popular</span>
                        @endif
                        <h3 class="font-display text-2xl font-semibold">{{ $plan->name }}</h3>
                        @if ($plan->description)
                            <p @class(['mt-2 text-sm', 'text-fin-paper/70' => $isFeatured, 'text-fin-body' => ! $isFeatured])>{{ $plan->description }}</p>
                        @endif
                        <p class="mt-6 flex items-baseline gap-1.5">
                            <span class="font-mono text-4xl font-medium tracking-tight">{{ \App\Support\Money::format($plan->price_amount, $plan->currency) }}</span>
                            <span @class(['text-sm', 'text-fin-paper/60' => $isFeatured, 'text-fin-muted' => ! $isFeatured])>/ {{ $plan->billing_period === \App\Enums\BillingPeriod::Yearly ? 'year' : 'month' }}</span>
                        </p>
                        @if ($plan->trial_days > 0)
                            <p @class(['mt-1 text-sm font-semibold', 'text-fin-gold' => $isFeatured, 'text-fin-teal' => ! $isFeatured])>{{ $plan->trial_days }}-day free trial</p>
                        @endif
                        <ul class="mt-7 space-y-3 text-sm">
                            <li class="flex gap-2.5"><x-marketing.icon name="check" class="h-5 w-5 shrink-0 text-fin-gold" /> {{ $limitLabel($plan->max_branches, 'branches') }}</li>
                            <li class="flex gap-2.5"><x-marketing.icon name="check" class="h-5 w-5 shrink-0 text-fin-gold" /> {{ $limitLabel($plan->max_staff, 'staff accounts') }}</li>
                            <li class="flex gap-2.5"><x-marketing.icon name="check" class="h-5 w-5 shrink-0 text-fin-gold" /> {{ $limitLabel($plan->max_customers, 'customers') }}</li>
                        </ul>
                        <a href="{{ route('marketing.demo') }}" @class(['btn mt-8 w-full', 'btn-gold' => $isFeatured, 'btn-ghost' => ! $isFeatured])>Get started</a>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="mx-auto mt-10 flex max-w-4xl flex-col items-start gap-5 rounded-3xl border border-dashed border-fin-ink/25 bg-fin-card/70 p-7 sm:flex-row sm:items-center sm:justify-between" data-reveal>
            <div class="flex gap-4">
                <x-marketing.icon name="monitor" class="h-8 w-8 shrink-0 text-fin-teal" />
                <div>
                    <h3 class="font-bold">Windows desktop licence</h3>
                    <p class="mt-1 text-sm text-fin-body">Run a branch offline on the desktop app — {{ \App\Support\Money::format($licence['price'], $licence['currency']) }} per device for {{ $licence['duration_days'] }} days.</p>
                </div>
            </div>
            <a href="{{ route('license.activate') }}" class="btn btn-ink shrink-0 !py-2.5 text-sm">Buy a licence</a>
        </div>
    </div>
</section>
