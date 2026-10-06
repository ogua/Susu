@php
    $field = 'mt-1.5 block w-full rounded-xl border border-fin-line bg-fin-paper px-4 py-3 text-[0.95rem] text-fin-ink placeholder:text-fin-muted/70 focus:border-fin-teal focus:bg-fin-card focus:outline-none focus:ring-4 focus:ring-fin-teal/15';
    $supportEmail = config('platform.support_email') ?: config('marketing.email');
    $supportPhone = config('platform.support_phone') ?: config('marketing.phone');
@endphp
<x-marketing.layout
    title="Book a demo | {{ config('marketing.product_name') }}"
    description="Book a free OguaFinance demo for your susu, microfinance or savings & loans institution, or reach the team by phone, WhatsApp or email."
>
    <section class="ledger-paper py-16 md:py-24">
        <div class="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-5">
                <p class="eyebrow">Book a demo</p>
                <h1 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">See your own institution in {{ config('marketing.product_name') }}.</h1>
                <p class="mt-5 text-lg leading-relaxed text-fin-body">Tell us a little about how you collect and lend today. We'll call to arrange a walkthrough set up with your own products and branches — free, with no obligation.</p>

                <ul class="mt-10 space-y-5">
                    <li class="flex gap-4">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-fin-card text-fin-teal shadow-sm"><x-marketing.icon name="phone" /></span>
                        <div><p class="text-sm font-semibold text-fin-muted">Call us</p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $supportPhone) }}" class="font-bold">{{ $supportPhone }}</a></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-fin-card text-fin-teal shadow-sm"><x-marketing.icon name="whatsapp" /></span>
                        <div><p class="text-sm font-semibold text-fin-muted">WhatsApp</p><a href="https://wa.me/{{ config('marketing.whatsapp') }}" rel="noopener" class="font-bold">Chat with the team</a></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-fin-card text-fin-teal shadow-sm"><x-marketing.icon name="mail" /></span>
                        <div><p class="text-sm font-semibold text-fin-muted">Email</p><a href="mailto:{{ $supportEmail }}" class="font-bold break-all">{{ $supportEmail }}</a></div>
                    </li>
                </ul>
            </div>

            <div class="lg:col-span-7">
                <div class="rounded-3xl border border-fin-line bg-fin-card p-6 shadow-[0_30px_60px_-40px_rgb(28_23_18/0.4)] sm:p-9">
                    @if (session('demoRequestSent'))
                        <div class="mb-7 flex gap-3 rounded-2xl bg-fin-teal-soft px-5 py-4 text-fin-teal" role="status">
                            <x-marketing.icon name="check" class="mt-0.5 h-5 w-5 shrink-0" />
                            <p><span class="font-bold">Thank you — we've got your request.</span> Someone from our team will contact you within one working day.</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-7 rounded-2xl border border-fin-clay/30 bg-fin-clay/5 px-5 py-4 text-sm text-fin-clay" role="alert">
                            <p class="font-bold">Please check the highlighted fields.</p>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('marketing.demo.store') }}" class="grid gap-5 sm:grid-cols-2" novalidate>
                        @csrf
                        <input type="hidden" name="{{ \App\Http\Requests\Marketing\StoreDemoRequestRequest::STARTED_FIELD }}" value="{{ $formStarted }}">
                        {{-- Honeypot: hidden from people and screen readers; only bots fill it in. --}}
                        <div class="absolute -left-[9999px]" aria-hidden="true">
                            <label for="{{ \App\Http\Requests\Marketing\StoreDemoRequestRequest::HONEYPOT_FIELD }}">Website</label>
                            <input type="text" id="{{ \App\Http\Requests\Marketing\StoreDemoRequestRequest::HONEYPOT_FIELD }}" name="{{ \App\Http\Requests\Marketing\StoreDemoRequestRequest::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off">
                        </div>

                        @foreach ([
                            ['name' => 'name', 'label' => 'Your name', 'type' => 'text', 'autocomplete' => 'name'],
                            ['name' => 'organisation', 'label' => 'Organisation name', 'type' => 'text', 'autocomplete' => 'organization'],
                            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'email'],
                            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'autocomplete' => 'tel'],
                        ] as $input)
                            <div>
                                <label for="{{ $input['name'] }}" class="text-sm font-bold">{{ $input['label'] }}</label>
                                <input type="{{ $input['type'] }}" id="{{ $input['name'] }}" name="{{ $input['name'] }}" value="{{ old($input['name']) }}" autocomplete="{{ $input['autocomplete'] }}" required
                                    @error($input['name']) aria-invalid="true" aria-describedby="{{ $input['name'] }}-error" @enderror
                                    class="{{ $field }} @error($input['name']) !border-fin-clay @enderror">
                                @error($input['name'])
                                    <p id="{{ $input['name'] }}-error" class="mt-1.5 text-sm text-fin-clay">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach

                        <div>
                            <label for="organisation_type" class="text-sm font-bold">Type of organisation</label>
                            <select id="organisation_type" name="organisation_type" required class="{{ $field }} @error('organisation_type') !border-fin-clay @enderror">
                                <option value="" disabled @selected(! old('organisation_type'))>Choose one</option>
                                @foreach ($organisationTypes as $type)
                                    <option value="{{ $type->value }}" @selected(old('organisation_type') === $type->value)>{{ $type->getLabel() }}</option>
                                @endforeach
                            </select>
                            @error('organisation_type')
                                <p class="mt-1.5 text-sm text-fin-clay">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="branches_count" class="text-sm font-bold">Number of branches <span class="font-normal text-fin-muted">(optional)</span></label>
                            <input type="number" min="1" id="branches_count" name="branches_count" value="{{ old('branches_count') }}" class="{{ $field }} @error('branches_count') !border-fin-clay @enderror">
                            @error('branches_count')
                                <p class="mt-1.5 text-sm text-fin-clay">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="message" class="text-sm font-bold">How do you work today? <span class="font-normal text-fin-muted">(optional)</span></label>
                            <textarea id="message" name="message" rows="4" placeholder="e.g. 12 agents collecting daily susu in Kumasi, records kept on paper cards and Excel." class="{{ $field }}">{{ old('message') }}</textarea>
                        </div>

                        <div class="flex flex-col gap-4 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-fin-muted">We use these details only to arrange your demo. See our <a href="{{ route('marketing.privacy') }}" class="underline">privacy policy</a>.</p>
                            <button type="submit" class="btn btn-gold shrink-0">Request my demo <x-marketing.icon name="arrow-right" class="h-4 w-4" /></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
