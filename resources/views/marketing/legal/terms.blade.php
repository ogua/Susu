{{-- DRAFT — boilerplate terms; needs legal review before launch. --}}
@php
    $product = config('marketing.product_name');
    $company = config('marketing.company_name');
    $email = config('platform.support_email') ?: config('marketing.email');
@endphp
<x-marketing.layout
    title="Terms of service | {{ $product }}"
    description="The terms that apply to using {{ $product }}, provided by {{ $company }}."
>
    <section class="py-16 md:py-24">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <p class="eyebrow">Legal</p>
            <h1 class="mt-4 font-display text-4xl font-semibold tracking-tight md:text-5xl">Terms of service</h1>
            <p class="mt-3 text-sm text-fin-muted">Last updated 6 October 2026</p>

            <div class="prose-legal mt-10">
                <p>These terms apply to {{ $product }}, provided by {{ $company }} ("we", "us"), including the website, web application, and mobile and desktop apps. By using {{ $product }} you agree to them. If you use it on behalf of an institution, you confirm you may accept these terms for that institution.</p>

                <h2>The service</h2>
                <p>{{ $product }} is software for managing susu collections, savings, loans and related accounting. It is a record-keeping and management tool: it does not hold or move customer funds itself, and it does not provide financial, legal or regulatory advice. Each institution remains responsible for its own licensing, compliance and decisions.</p>

                <h2>Accounts and security</h2>
                <p>Institutions are responsible for the staff accounts they create, the roles they assign and keeping sign-in details secure. Tell us promptly at <a href="mailto:{{ $email }}">{{ $email }}</a> if you suspect unauthorised access.</p>

                <h2>Subscriptions and licences</h2>
                <p>Web subscriptions are billed per plan and billing period shown at sign-up or on your invoice. Windows desktop licences are sold per device for a fixed period. If an invoice remains unpaid after the grace period, access may be suspended until it is paid; your data is kept during suspension.</p>

                <h2>Your data</h2>
                <p>Institutions own the data they put into {{ $product }}. We process it as described in our <a href="{{ route('marketing.privacy') }}">privacy policy</a>, and on request at the end of a subscription we provide an export of it before it is deleted.</p>

                <h2>Acceptable use</h2>
                <p>You must not use {{ $product }} for unlawful activity, attempt to access other institutions' data, interfere with the service's security or operation, or resell it without our written agreement.</p>

                <h2>Availability and liability</h2>
                <p>We work to keep {{ $product }} available and accurate, but the service is provided as is. To the extent the law allows, we are not liable for indirect or consequential losses, and our total liability is limited to the fees paid to us for the service in the twelve months before the claim.</p>

                <h2>Changes and governing law</h2>
                <p>We may update these terms and will post changes on this page. These terms are governed by the laws of the Republic of Ghana.</p>
            </div>
        </div>
    </section>
</x-marketing.layout>
