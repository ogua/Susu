@php
    $familyCompany = app(\App\Services\OguaFamily::class)->company();
    $supportEmail = config('platform.support_email') ?: config('marketing.email');
    $supportPhone = config('platform.support_phone') ?: config('marketing.phone');
@endphp
<footer class="on-dark bg-fin-ink text-fin-paper/75">
    <div class="mx-auto max-w-7xl px-4 pt-16 pb-10 sm:px-6 lg:px-8">
        <div class="grid gap-12 md:grid-cols-2 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <a href="{{ route('marketing.home') }}" class="flex items-center gap-2.5 text-fin-paper">
                    <img src="{{ asset('images/marketing/oguafinance-mark.svg') }}" alt="" class="h-9 w-9" width="36" height="36">
                    <span class="font-display text-xl font-semibold">Ogua<span class="text-fin-gold">Finance</span></span>
                </a>
                <p class="mt-4 max-w-sm text-sm leading-relaxed">
                    Susu, savings and loan management for collectors, microfinance institutions and savings &amp; loans companies across Ghana.
                </p>
                <a href="https://wa.me/{{ config('marketing.whatsapp') }}" rel="noopener" class="btn btn-ghost mt-6 !py-2 text-sm">
                    <x-marketing.icon name="whatsapp" class="h-4 w-4" /> Chat on WhatsApp
                </a>
            </div>

            <div class="lg:col-span-2">
                <h2 class="eyebrow">Product</h2>
                <ul class="mt-4 space-y-2.5 text-sm [&_a]:transition [&_a:hover]:text-fin-gold">
                    <li><a href="{{ route('marketing.home') }}#features">Features</a></li>
                    <li><a href="{{ route('marketing.home') }}#pricing">Pricing</a></li>
                    <li><a href="{{ route('marketing.home') }}#download">Download</a></li>
                    <li><a href="{{ route('marketing.demo') }}">Book a demo</a></li>
                    <li><a href="{{ filament()->getPanel('admin')->getLoginUrl() }}">Staff sign in</a></li>
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="eyebrow">The Ogua family</h2>
                <ul class="mt-4 space-y-2.5 text-sm [&_a]:transition [&_a:hover]:text-fin-gold [&_.ogua-family-category]:block [&_.ogua-family-category]:text-xs [&_.ogua-family-category]:text-fin-paper/50">
                    <x-ogua-family.links show-category with-company />
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="eyebrow">Get in touch</h2>
                <ul class="mt-4 space-y-3 text-sm [&_a]:transition [&_a:hover]:text-fin-gold">
                    <li class="flex gap-2.5"><x-marketing.icon name="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-fin-gold" /> {{ config('marketing.address') }}</li>
                    <li class="flex gap-2.5"><x-marketing.icon name="phone" class="mt-0.5 h-4 w-4 shrink-0 text-fin-gold" /> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $supportPhone) }}">{{ $supportPhone }}</a></li>
                    <li class="flex gap-2.5"><x-marketing.icon name="mail" class="mt-0.5 h-4 w-4 shrink-0 text-fin-gold" /> <a href="mailto:{{ $supportEmail }}" class="break-all">{{ $supportEmail }}</a></li>
                </ul>
            </div>
        </div>

        <div class="ledger-rule mt-14 !opacity-25 [border-color:var(--color-fin-paper)]"></div>

        <div class="mt-6 flex flex-col gap-3 text-xs sm:flex-row sm:items-center sm:justify-between">
            <p>
                &copy; {{ now()->year }} {{ config('marketing.product_name') }} &middot; A product of
                <a href="{{ $familyCompany['url'] }}" rel="noopener" class="font-semibold text-fin-paper hover:text-fin-gold">{{ $familyCompany['name'] }}</a>
            </p>
            <ul class="flex gap-5 [&_a:hover]:text-fin-gold">
                <li><a href="{{ route('marketing.privacy') }}">Privacy policy</a></li>
                <li><a href="{{ route('marketing.terms') }}">Terms of service</a></li>
            </ul>
        </div>
    </div>
</footer>
