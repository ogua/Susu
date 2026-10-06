<section class="px-4 pb-20 sm:px-6 md:pb-28 lg:px-8">
    <div class="on-dark relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-fin-ink px-6 py-16 text-center text-fin-paper md:px-12 md:py-20" data-reveal>
        <div class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:repeating-linear-gradient(to_bottom,transparent_0,transparent_31px,var(--color-fin-paper)_31px,var(--color-fin-paper)_32px)]" aria-hidden="true"></div>
        <p class="eyebrow relative">Get started</p>
        <h2 class="relative mx-auto mt-4 max-w-3xl font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">
            Put down the paper card. <span class="italic text-fin-gold">Pick up the whole picture.</span>
        </h2>
        <p class="relative mx-auto mt-5 max-w-xl text-lg text-fin-paper/75">See {{ config('marketing.product_name') }} with your own products and branches in a free, no-obligation demo.</p>
        <div class="relative mt-9 flex flex-wrap justify-center gap-3">
            <a href="{{ route('marketing.demo') }}" class="btn btn-gold">Book a free demo <x-marketing.icon name="arrow-right" class="h-4 w-4" /></a>
            <a href="https://wa.me/{{ config('marketing.whatsapp') }}" rel="noopener" class="btn btn-ghost"><x-marketing.icon name="whatsapp" class="h-4 w-4" /> WhatsApp us</a>
        </div>
    </div>
</section>
