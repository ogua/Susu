@php
    $features = [
        ['icon' => 'savings', 'title' => 'Susu & savings products', 'body' => 'Daily susu with cycles and commission, target savings, fixed deposits and cooperative shares — each with its own rules and statements.'],
        ['icon' => 'group', 'title' => 'Susu groups & rotations', 'body' => 'Run rotating group contributions with scheduled payouts, and see every member\'s standing at a glance.'],
        ['icon' => 'loan', 'title' => 'Individual & group loans', 'body' => 'Loan products with flat or reducing-balance interest, charges, guarantors and collateral, from application through approval, disbursement and repayment.'],
        ['icon' => 'wallet', 'title' => 'Withdrawal approvals', 'body' => 'Customers request withdrawals in their app; staff approve or reject with a full audit trail.'],
        ['icon' => 'ledger', 'title' => 'Double-entry accounting', 'body' => 'Every collection, disbursement and fee posts to a real general ledger. Trial balance, income statement, balance sheet and cash position are always up to date.'],
        ['icon' => 'momo', 'title' => 'Mobile Money payments', 'body' => 'Accept MTN MoMo, Telecel Cash and AirtelTigo Money through Paystack — straight into the right customer account.'],
        ['icon' => 'sms', 'title' => 'SMS receipts & alerts', 'body' => 'Customers get an SMS for every deposit and withdrawal, sent under your own sender ID.'],
        ['icon' => 'map-pin', 'title' => 'Agent tracking & collection sheets', 'body' => 'See agents on a live map while on duty, print collection sheets per route, and compare collected against expected.'],
        ['icon' => 'branches', 'title' => 'Multi-branch', 'body' => 'Each branch keeps its own customers, agents and books, while head office sees everything consolidated.'],
        ['icon' => 'shield', 'title' => 'Roles & two-factor sign-in', 'body' => 'Fine-grained staff roles, two-factor authentication and an activity log of who did what, and when.'],
        ['icon' => 'user-check', 'title' => 'Customer self-service', 'body' => 'Customers check balances, download statements and request withdrawals from their own mobile app.'],
        ['icon' => 'chart', 'title' => 'Reports that matter', 'body' => 'Collections, defaulters, loan portfolio, agent performance and customer balances — on screen, PDF or Excel.'],
    ];
@endphp
<section id="features" class="py-20 md:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-12" data-reveal>
            <div class="lg:col-span-5">
                <p class="eyebrow">Features</p>
                <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">Everything a susu or microfinance office runs on.</h2>
            </div>
            <p class="text-lg leading-relaxed text-fin-body lg:col-span-6 lg:col-start-7 lg:self-end">
                Built for how institutions in Ghana actually work — daily field collection, cash remittances, mobile money and SMS — not a foreign banking package squeezed to fit.
            </p>
        </div>

        <div class="mt-14 grid overflow-hidden rounded-3xl border border-fin-line bg-fin-line sm:grid-cols-2 lg:grid-cols-3 [&>*]:bg-fin-card" style="gap: 1px">
            @foreach ($features as $feature)
                <article class="group p-7 transition hover:bg-fin-paper" data-reveal>
                    <x-marketing.icon :name="$feature['icon']" class="h-7 w-7 text-fin-teal transition group-hover:text-fin-gold-ink" />
                    <h3 class="mt-5 text-lg font-bold">{{ $feature['title'] }}</h3>
                    <p class="mt-2 text-[0.95rem] leading-relaxed text-fin-body">{{ $feature['body'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
