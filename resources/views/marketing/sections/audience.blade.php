@php
    $audiences = [
        ['title' => 'Susu enterprises & collectors', 'body' => 'Move from paper cards to an app your agents carry and a ledger you can audit — commission and cycle rollovers calculated for you.'],
        ['title' => 'Microfinance institutions', 'body' => 'Savings, loans and field collection across branches, with the accounting and portfolio reports management and auditors ask for.'],
        ['title' => 'Savings & loans companies', 'body' => 'Loan products, eligibility checks, repayment schedules and arrears tracking alongside fixed and target savings.'],
        ['title' => 'Cooperatives & credit unions', 'body' => 'Member shares, group contributions and loans to members, with statements every member can understand.'],
    ];
    $reports = ['Trial balance', 'Income statement', 'Balance sheet', 'Cash position', 'Loan portfolio', 'Defaulters', 'Collections', 'Agent performance', 'Customer balances', 'Withdrawals', 'General ledger', 'Activity log'];
@endphp
<section class="bg-fin-card py-20 md:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">Who it's for</p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">One platform, whatever the size of your book.</h2>
        </div>

        <div class="mt-14 grid gap-6 md:grid-cols-2">
            @foreach ($audiences as $audience)
                <article class="rounded-3xl border border-fin-line bg-fin-paper p-7 md:p-8" data-reveal>
                    <h3 class="font-display text-2xl font-semibold">{{ $audience['title'] }}</h3>
                    <p class="mt-3 leading-relaxed text-fin-body">{{ $audience['body'] }}</p>
                </article>
            @endforeach
        </div>

        <div class="mt-16 rounded-3xl bg-fin-teal p-8 text-fin-paper md:p-12" data-reveal>
            <div class="grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <p class="font-mono text-xs tracking-[0.14em] text-fin-gold-soft uppercase">Reports</p>
                    <h3 class="mt-3 font-display text-3xl font-semibold">Audit-ready books, every evening.</h3>
                    <p class="mt-4 leading-relaxed text-fin-paper/80">Because every transaction posts to the ledger as it happens, the numbers are ready when your board, auditor or regulator asks — on screen, as PDF or as Excel.</p>
                </div>
                <ul class="grid grid-cols-2 gap-x-6 gap-y-3 self-center text-sm font-semibold sm:grid-cols-3 lg:col-span-7">
                    @foreach ($reports as $report)
                        <li class="flex items-center gap-2"><x-marketing.icon name="check" class="h-4 w-4 shrink-0 text-fin-gold" /> {{ $report }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>
