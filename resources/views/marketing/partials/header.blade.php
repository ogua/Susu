@php
    $navLinks = [
        ['label' => 'Features', 'href' => route('marketing.home').'#features'],
        ['label' => 'How it works', 'href' => route('marketing.home').'#how-it-works'],
        ['label' => 'Pricing', 'href' => route('marketing.home').'#pricing'],
        ['label' => 'Download', 'href' => route('marketing.home').'#download'],
        ['label' => 'FAQ', 'href' => route('marketing.home').'#faq'],
    ];
    $loginUrl = filament()->getPanel('admin')->getLoginUrl();
@endphp
<header data-site-header class="sticky top-0 z-50 border-b border-transparent">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-3.5 sm:px-6 lg:px-8">
        <a href="{{ route('marketing.home') }}" class="flex items-center gap-2.5" aria-label="{{ config('marketing.product_name') }} home">
            <img src="{{ asset('images/marketing/oguafinance-mark.svg') }}" alt="" class="h-9 w-9" width="36" height="36">
            <span class="font-display text-xl font-semibold tracking-tight">Ogua<span class="text-fin-gold-ink">Finance</span></span>
        </a>

        <nav aria-label="Main" class="hidden lg:block">
            <ul class="flex items-center gap-7 text-sm font-semibold text-fin-body">
                @foreach ($navLinks as $link)
                    <li><a href="{{ $link['href'] }}" class="transition hover:text-fin-ink">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="hidden items-center gap-3 lg:flex">
            <a href="{{ $loginUrl }}" class="text-sm font-semibold text-fin-body transition hover:text-fin-ink">Sign in</a>
            <a href="{{ route('marketing.demo') }}" class="btn btn-ink !py-2.5 text-sm">Book a demo</a>
        </div>

        <button type="button" data-nav-toggle aria-expanded="false" aria-controls="mobile-nav" class="-mr-2 rounded-full p-2 lg:hidden">
            <span class="sr-only">Menu</span>
            <x-marketing.icon name="menu" class="h-6 w-6" data-icon-open />
            <x-marketing.icon name="close" class="hidden h-6 w-6" data-icon-close />
        </button>
    </div>

    <div id="mobile-nav" data-mobile-nav class="border-t border-fin-line bg-fin-paper lg:hidden">
        <nav aria-label="Mobile" class="mx-auto max-w-7xl px-4 py-4 sm:px-6">
            <ul class="grid gap-1 text-base font-semibold">
                @foreach ($navLinks as $link)
                    <li><a href="{{ $link['href'] }}" class="block rounded-lg px-3 py-2.5 hover:bg-fin-paper-deep">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <a href="{{ $loginUrl }}" class="btn btn-ghost">Sign in</a>
                <a href="{{ route('marketing.demo') }}" class="btn btn-ink">Book a demo</a>
            </div>
        </nav>
    </div>
</header>
