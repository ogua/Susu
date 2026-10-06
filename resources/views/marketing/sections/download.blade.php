@php
    $platforms = [
        ['icon' => 'globe', 'name' => 'Web app', 'detail' => 'Head office and branches, in any browser', 'url' => filament()->getPanel('admin')->getLoginUrl(), 'cta' => 'Sign in'],
        ['icon' => 'smartphone', 'name' => 'Android', 'detail' => 'Agents in the field and customers', 'url' => $downloads['android'] ?? null, 'cta' => 'Get it on Google Play'],
        ['icon' => 'smartphone', 'name' => 'iPhone', 'detail' => 'Customers checking their savings', 'url' => $downloads['ios'] ?? null, 'cta' => 'Download on the App Store'],
        ['icon' => 'monitor', 'name' => 'Windows desktop', 'detail' => 'Branch offices that need to work offline', 'url' => $downloads['desktop'] ?? null, 'cta' => 'Download for Windows'],
    ];
@endphp
<section id="download" class="bg-fin-card py-20 md:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">Download</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">On every screen your team uses.</h2>
        </div>

        <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($platforms as $platform)
                <li class="flex flex-col rounded-3xl border border-fin-line bg-fin-paper p-6" data-reveal>
                    <x-marketing.icon :name="$platform['icon']" class="h-8 w-8 text-fin-teal" />
                    <h3 class="mt-5 text-lg font-bold">{{ $platform['name'] }}</h3>
                    <p class="mt-1 flex-1 text-sm text-fin-body">{{ $platform['detail'] }}</p>
                    @if ($platform['url'])
                        <a href="{{ $platform['url'] }}" rel="noopener" class="btn btn-ink mt-6 !py-2.5 text-sm">{{ $platform['cta'] }}</a>
                    @else
                        <span class="mt-6 inline-flex items-center justify-center rounded-full border border-dashed border-fin-ink/25 px-4 py-2.5 text-sm font-semibold text-fin-muted">Coming soon</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>
