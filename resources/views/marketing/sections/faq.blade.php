@php
    $faqs = [
        ['q' => 'Do my agents need internet to record collections?', 'a' => 'No. The agent app saves collections on the phone when there is no network and uploads them automatically once it reconnects. Each record has its own reference, so a collection is never posted twice even if the upload is retried.'],
        ['q' => 'Can customers pay with Mobile Money?', 'a' => 'Yes. Payments through MTN MoMo, Telecel Cash and AirtelTigo Money are processed by Paystack and credited to the right customer account automatically.'],
        ['q' => 'Do customers get SMS receipts?', 'a' => 'Yes. Customers receive an SMS for deposits, withdrawals, loan disbursements and repayments, sent under your institution\'s own sender ID.'],
        ['q' => 'Can we run more than one branch?', 'a' => 'Yes. Each branch has its own customers, agents and books, and head office can see and report across all of them. Plan limits on branches, staff and customers are shown in the pricing above.'],
        ['q' => 'Is the accounting real double-entry?', 'a' => 'Yes. Every collection, withdrawal, disbursement, repayment and fee posts balanced journal entries to a general ledger, from which the trial balance, income statement and balance sheet are produced.'],
        ['q' => 'Who can see our data?', 'a' => 'Only your staff, according to the roles you give them. Staff sign in with two-factor authentication, every sensitive action is logged, and your data is never shared with other institutions on the platform.'],
        ['q' => 'Can we move from paper cards or another system?', 'a' => 'Yes. During onboarding our team works with you to set up your savings and loan products, branches and existing customers, and trains your agents and office staff.'],
        ['q' => 'What does the Windows desktop app do?', 'a' => 'It lets a branch office keep working without internet — registering customers, recording transactions and running reports — and syncs everything with the server when connected. It is licensed per device.'],
    ];
@endphp
<section id="faq" class="py-20 md:py-28">
    <div class="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-12 lg:px-8">
        <div class="lg:col-span-4" data-reveal>
            <p class="eyebrow">FAQ</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight">Questions we hear often.</h2>
            <p class="mt-5 text-fin-body">Something else on your mind? <a href="{{ route('marketing.demo') }}" class="font-semibold text-fin-teal underline underline-offset-4">Ask us directly</a>.</p>
        </div>
        <div class="divide-y divide-fin-line border-y border-fin-line lg:col-span-8">
            @foreach ($faqs as $index => $faq)
                <div data-faq-item>
                    <h3>
                        <button type="button" data-faq-trigger aria-expanded="false" aria-controls="faq-panel-{{ $index }}" class="flex w-full items-center justify-between gap-6 py-5 text-left text-lg font-bold">
                            {{ $faq['q'] }}
                            <x-marketing.icon name="plus" class="h-5 w-5 shrink-0 text-fin-gold-ink transition-transform" data-faq-icon />
                        </button>
                    </h3>
                    <div id="faq-panel-{{ $index }}" data-faq-panel>
                        <div>
                            <p class="pb-6 leading-relaxed text-fin-body">{{ $faq['a'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
