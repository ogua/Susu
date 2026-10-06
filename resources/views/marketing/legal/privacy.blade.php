{{-- DRAFT — written against Ghana's Data Protection Act, 2012 (Act 843); needs legal review before launch. --}}
@php
    $product = config('marketing.product_name');
    $company = config('marketing.company_name');
    $email = config('platform.support_email') ?: config('marketing.email');
@endphp
<x-marketing.layout
    title="Privacy policy | {{ $product }}"
    description="How {{ $product }} and {{ $company }} collect, use and protect personal data."
>
    <section class="py-16 md:py-24">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <p class="eyebrow">Legal</p>
            <h1 class="mt-4 font-display text-4xl font-semibold tracking-tight md:text-5xl">Privacy policy</h1>
            <p class="mt-3 text-sm text-fin-muted">Last updated 6 October 2026</p>

            <div class="prose-legal mt-10">
                <p>{{ $product }} is software operated by {{ $company }} ("we", "us"), {{ config('marketing.address') }}. This policy explains what personal data we handle through the {{ $product }} website, web application and mobile and desktop apps, why, and the rights you have under Ghana's Data Protection Act, 2012 (Act 843).</p>

                <h2>Two roles we play</h2>
                <p><strong>When you use our website</strong> — for example to book a demo — we decide how your data is used and are the data controller.</p>
                <p><strong>When a susu, microfinance or savings &amp; loans institution uses {{ $product }}</strong> to manage its customers, that institution is the data controller for its customers' data and we process it on its behalf, only on its instructions. If you are a customer of such an institution, please contact the institution first about your data; we will help it respond.</p>

                <h2>What we collect</h2>
                <ul>
                    <li><strong>Demo requests:</strong> your name, organisation, email, phone number, type of organisation, number of branches and any message you send.</li>
                    <li><strong>Institution staff accounts:</strong> name, email, phone, role, sign-in activity and, for field agents on duty, device location.</li>
                    <li><strong>Institution customers</strong> (on the institution's behalf): identity and contact details, identification documents, beneficiaries and next of kin, account and loan transactions, and statements.</li>
                    <li><strong>Technical data:</strong> IP address, device and app version, and logs needed to keep the service secure and working.</li>
                </ul>

                <h2>Why we use it</h2>
                <ul>
                    <li>To respond to demo requests and provide the service an institution has subscribed to.</li>
                    <li>To send transaction SMS and emails the institution has enabled.</li>
                    <li>To process payments through our payment provider (Paystack).</li>
                    <li>To secure accounts (for example two-factor sign-in), prevent fraud and keep audit records.</li>
                    <li>To meet legal and regulatory obligations.</li>
                </ul>

                <h2>Who we share it with</h2>
                <p>We do not sell personal data. We share it only with service providers we need to run {{ $product }} — hosting, SMS delivery (Arkesel), payment processing (Paystack) and email delivery — under obligations to protect it, or where the law requires us to.</p>

                <h2>How long we keep it</h2>
                <p>Institutions decide how long their customer records are kept, within the limits of the law; identification documents are purged automatically after the retention period the institution sets. Demo requests are kept for as long as needed to follow up, and no longer than two years.</p>

                <h2>Security</h2>
                <p>Data is encrypted in transit, access is restricted by role, staff can be required to use two-factor sign-in, and sensitive actions are logged. Each institution can see only its own data.</p>

                <h2>Your rights</h2>
                <p>Under Act 843 you may ask to see the personal data held about you, ask for it to be corrected or deleted, and object to its use for direct marketing. You may also complain to the Data Protection Commission of Ghana. To exercise these rights, email <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>

                <h2>Changes</h2>
                <p>We will post any changes to this policy on this page and update the date above.</p>
            </div>
        </div>
    </section>
</x-marketing.layout>
