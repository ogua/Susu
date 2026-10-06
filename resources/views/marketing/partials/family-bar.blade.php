@php($familyCompany = app(\App\Services\OguaFamily::class)->company())
<div class="on-dark bg-fin-ink text-[0.78rem] text-fin-paper/75">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2 sm:px-6 lg:px-8">
        <p>
            An
            <a href="{{ $familyCompany['url'] }}" rel="noopener" class="font-semibold text-fin-paper underline-offset-4 hover:underline">{{ $familyCompany['name'] }}</a>
            product
        </p>
        <nav aria-label="Other Ogua products" class="hidden md:block">
            <ul class="flex items-center gap-5 [&_a]:text-fin-paper/75 [&_a]:transition [&_a:hover]:text-fin-gold">
                <li class="font-mono text-[0.68rem] uppercase tracking-[0.14em] text-fin-paper/50">Also from Ogua</li>
                <x-ogua-family.links />
            </ul>
        </nav>
    </div>
</div>
