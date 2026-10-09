<?php

namespace App\Services\Ussd;

use App\Actions\Payments\VerifyPaymentIntentAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\AccountStatus;
use App\Enums\LoanStatus;
use App\Enums\SavingsProductType;
use App\Enums\WithdrawalStatus;
use App\Jobs\Ussd\InitiateUssdContributionCharge;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Services\Payments\PaystackClient;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Answers the central Ogua USSD platform for Susu: who a phone number belongs to,
 * and short (≤160 character) replies for each USSD menu action. Money moves only
 * through the same Actions the mobile app uses.
 *
 * Replies are arrays in the platform's connector format:
 * {message, continue, state?, transaction?}.
 */
class SusuUssdService
{
    /**
     * Trailing digits two phone numbers are compared on — the subscriber number
     * without a trunk 0 or country code — because phones are stored as typed.
     */
    private const PHONE_MATCH_DIGITS = 9;

    private const CONFIRM_OPTIONS = "1. Confirm\n2. Cancel\n3. Other network";

    private const MAX_CONTRIBUTION_DAYS = 31;

    public function __construct(
        private RequestWithdrawalAction $requestWithdrawal,
        private VerifyPaymentIntentAction $paymentRules,
        private PaystackClient $paystack,
        private UssdServiceUser $serviceUser,
    ) {}

    /**
     * The active Susu companies the USSD platform can onboard as tenants.
     *
     * @return list<array{tenant_ref: string, name: string}>
     */
    public function tenants(): array
    {
        return Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company): array => ['tenant_ref' => (string) $company->id, 'name' => (string) $company->name])
            ->all();
    }

    /**
     * @return list<array{tenant_ref: string, tenant_name: string, subject_ref: string, label: string}>
     */
    public function identify(string $msisdn, ?string $tenantRef = null): array
    {
        return $this->customers($msisdn, $tenantRef)
            ->map(fn (Customer $customer): array => [
                'tenant_ref' => (string) $customer->company_id,
                'tenant_name' => (string) $customer->company?->name,
                'subject_ref' => (string) $customer->id,
                'label' => trim("{$customer->first_name} {$customer->last_name}"),
            ])
            ->values()
            ->all();
    }

    /**
     * The customer, only if the calling phone number is theirs.
     */
    public function findCustomer(string $msisdn, string $tenantRef, string $subjectRef): ?Customer
    {
        return $this->customers($msisdn, $tenantRef)->firstWhere('id', $subjectRef);
    }

    public function balance(Customer $customer): string
    {
        $accounts = $this->activeAccounts($customer);

        if ($accounts->isEmpty()) {
            return 'You have no active savings accounts.';
        }

        return "Balances:\n".$accounts
            ->map(fn (SavingsAccount $account): string => $this->accountLabel($account).': '.Money::format($account->balance))
            ->implode("\n");
    }

    public function history(Customer $customer): string
    {
        $ledgerAccountIds = $this->activeAccounts($customer)->pluck('ledger_account_id')->filter()->all();

        $entries = JournalEntry::query()
            ->whereHas('lines', fn ($query) => $query->whereIn('ledger_account_id', $ledgerAccountIds))
            ->with(['lines' => fn ($query) => $query->whereIn('ledger_account_id', $ledgerAccountIds)])
            ->orderByDesc('recorded_at')
            ->limit(5)
            ->get();

        if ($entries->isEmpty()) {
            return 'No transactions yet.';
        }

        return "Recent transactions:\n".$entries
            ->map(function (JournalEntry $entry): string {
                $net = (int) $entry->lines->sum('credit') - (int) $entry->lines->sum('debit');

                return $entry->recorded_at?->format('j M').' '.($net >= 0 ? '+' : '-').Money::format(abs($net));
            })
            ->implode("\n");
    }

    public function loanBalance(Customer $customer): string
    {
        $loans = Loan::query()
            ->where('customer_id', $customer->id)
            ->where('status', LoanStatus::Disbursed)
            ->get(['loan_number', 'outstanding_balance']);

        if ($loans->isEmpty()) {
            return 'You have no active loans.';
        }

        return "Loans:\n".$loans
            ->map(fn (Loan $loan): string => "{$loan->loan_number}: ".Money::format((int) $loan->outstanding_balance).' owed')
            ->implode("\n");
    }

    /**
     * Withdrawal request, the same one the app makes: account → amount → confirm.
     * The platform's transaction id (sent on confirmation) is the client_reference,
     * so a retried confirmation can never create a second request.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function withdrawal(Customer $customer, ?string $input, array $state, ?string $transactionId): array
    {
        $accounts = $this->activeAccounts($customer)->filter(fn (SavingsAccount $account): bool => $account->balance > 0)->values();

        return match ($state['step'] ?? null) {
            'account' => $this->chooseAccount($accounts, (string) $input),
            'amount' => $this->enterAmount($accounts, (string) ($state['account_id'] ?? ''), (string) $input),
            'confirm' => $this->confirmWithdrawal($customer, $accounts, $state, (string) $input, $transactionId),
            default => $this->startWithdrawal($accounts),
        };
    }

    /**
     * MoMo contribution: account → days → confirm. The MoMo network comes from the number's
     * prefix; "Other network" on the confirm screen covers ported numbers, and an unknown
     * prefix is asked for. The charge is sent by a
     * delayed job after this final screen, because the phone can only show the MoMo
     * approval prompt once its USSD session has closed. The platform's transaction id
     * is the charge's client_reference, so a retried confirmation never charges twice.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function contribute(Customer $customer, string $msisdn, ?string $input, array $state, ?string $transactionId): array
    {
        $accounts = $this->activeAccounts($customer)
            ->filter(fn (SavingsAccount $account): bool => $account->product?->type !== SavingsProductType::Shares && $account->contribution_amount > 0)
            ->values();

        $account = $accounts->firstWhere('id', $state['account_id'] ?? null);

        return match ($state['step'] ?? null) {
            'account' => $this->chooseContributionAccount($accounts, (string) $input),
            'days' => $account === null ? $this->end('That account is no longer available.') : $this->enterDays($account, $msisdn, (string) $input),
            'network' => $account === null ? $this->end('That account is no longer available.') : $this->chooseNetwork($account, (int) $state['amount'], (string) $input),
            'confirm' => $this->confirmContribution($customer, $account, $msisdn, $state, (string) $input, $transactionId),
            default => $this->startContribution($accounts),
        };
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<string, mixed>
     */
    private function startContribution(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return $this->end('You have no account you can contribute to by USSD.');
        }

        if ($accounts->count() === 1) {
            return $this->askDays($accounts->first());
        }

        return $this->prompt(
            "Contribute to:\n".$accounts->values()->map(fn (SavingsAccount $account, int $index): string => ($index + 1).'. '.$this->accountLabel($account))->implode("\n"),
            ['step' => 'account'],
        );
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<string, mixed>
     */
    private function chooseContributionAccount(Collection $accounts, string $input): array
    {
        $account = ctype_digit($input) ? $accounts->get((int) $input - 1) : null;

        return $account === null ? $this->startContribution($accounts) : $this->askDays($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function askDays(SavingsAccount $account, ?string $notice = null): array
    {
        return $this->prompt(
            ltrim($notice."\nHow many days are you paying for?\n".Money::format($account->contribution_amount).' per day'),
            ['step' => 'days', 'account_id' => $account->id],
        );
    }

    /**
     * Collections must be a whole number of daily contributions (RecordCollectionAction).
     *
     * @return array<string, mixed>
     */
    private function enterDays(SavingsAccount $account, string $msisdn, string $input): array
    {
        $days = ctype_digit(trim($input)) ? (int) trim($input) : 0;

        if ($days < 1 || $days > self::MAX_CONTRIBUTION_DAYS) {
            return $this->askDays($account, 'Enter 1 to '.self::MAX_CONTRIBUTION_DAYS.' days.');
        }

        $amount = $days * $account->contribution_amount;
        $provider = MobileMoneyNetwork::detect($msisdn);

        return $provider === null
            ? $this->askNetwork($account, $amount)
            : $this->contributionConfirmation($account, $amount, $provider);
    }

    /**
     * @return array<string, mixed>
     */
    private function askNetwork(SavingsAccount $account, int $amount): array
    {
        return $this->prompt(
            "Pay with:\n".collect(MobileMoneyNetwork::options())->map(fn (string $provider, string $option): string => "{$option}. ".MobileMoneyNetwork::name($provider))->implode("\n"),
            ['step' => 'network', 'account_id' => $account->id, 'amount' => $amount],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function chooseNetwork(SavingsAccount $account, int $amount, string $input): array
    {
        $provider = MobileMoneyNetwork::options()[$input] ?? null;

        return $provider === null
            ? $this->askNetwork($account, $amount)
            : $this->contributionConfirmation($account, $amount, $provider);
    }

    /**
     * @return array<string, mixed>
     */
    private function contributionConfirmation(SavingsAccount $account, int $amount, string $provider): array
    {
        return [
            'message' => 'Pay '.Money::format($amount).' into '.$this->accountLabel($account).' with '.MobileMoneyNetwork::name($provider)."?\n".self::CONFIRM_OPTIONS,
            'continue' => true,
            'state' => ['step' => 'confirm', 'account_id' => $account->id, 'amount' => $amount, 'provider' => $provider],
            'transaction' => ['amount' => $amount, 'currency' => Money::DEFAULT_CURRENCY],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function confirmContribution(Customer $customer, ?SavingsAccount $account, string $msisdn, array $state, string $input, ?string $transactionId): array
    {
        if ($input === '2') {
            return $this->end('Contribution cancelled.');
        }

        if ($input === '3') {
            return $account === null
                ? $this->end('That account is no longer available.')
                : $this->askNetwork($account, (int) $state['amount']);
        }

        if ($input !== '1') {
            return $this->prompt(self::CONFIRM_OPTIONS, $state);
        }

        if ($account === null || $transactionId === null) {
            return $this->end('That contribution can no longer be completed.', ['status' => 'failed']);
        }

        $amount = (int) $state['amount'];

        try {
            $this->paymentRules->assertCreditable($this->serviceUser->initiatorFor($customer), $account, $amount);
        } catch (ValidationException $exception) {
            return $this->end((string) collect($exception->errors())->flatten()->first(), ['status' => 'failed']);
        }

        if (! $this->paystack->forCompany($account->company)->isConfigured()) {
            return $this->end('MoMo payments are not set up for your Susu office yet.', ['status' => 'failed']);
        }

        InitiateUssdContributionCharge::dispatch(
            $customer->id,
            $account->id,
            $amount,
            '0'.substr($msisdn, -9),
            (string) $state['provider'],
            $transactionId,
        )->delay(now()->addSeconds((int) config('services.ussd.charge_delay_seconds')));

        return $this->end(
            'You will get a MoMo prompt for '.Money::format($amount).' shortly. Enter your MoMo PIN to approve.',
            ['status' => 'pending', 'reference' => $transactionId],
        );
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<string, mixed>
     */
    private function startWithdrawal(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return $this->end('You have no account with funds to withdraw.');
        }

        if ($accounts->count() === 1) {
            return $this->askAmount($accounts->first());
        }

        return $this->prompt(
            "Withdraw from:\n".$accounts->values()->map(fn (SavingsAccount $account, int $index): string => ($index + 1).'. '.$this->accountLabel($account))->implode("\n"),
            ['step' => 'account'],
        );
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<string, mixed>
     */
    private function chooseAccount(Collection $accounts, string $input): array
    {
        $account = ctype_digit($input) ? $accounts->get((int) $input - 1) : null;

        return $account === null ? $this->startWithdrawal($accounts) : $this->askAmount($account);
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<string, mixed>
     */
    private function enterAmount(Collection $accounts, string $accountId, string $input): array
    {
        $account = $accounts->firstWhere('id', $accountId);

        if ($account === null) {
            return $this->end('That account is no longer available for withdrawal.');
        }

        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', trim($input))) {
            return $this->askAmount($account, 'Enter a valid amount.');
        }

        $amount = Money::toMinorUnits(trim($input));
        $available = $this->availableBalance($account);

        if ($amount <= 0 || $amount > $available) {
            return $this->askAmount($account, 'Available: '.Money::format($available).'.');
        }

        return [
            'message' => 'Withdraw '.Money::format($amount).' from '.$this->accountLabel($account)."?\n1. Confirm\n2. Cancel",
            'continue' => true,
            'state' => ['step' => 'confirm', 'account_id' => $account->id, 'amount' => $amount],
            'transaction' => ['amount' => $amount, 'currency' => Money::DEFAULT_CURRENCY],
        ];
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function confirmWithdrawal(Customer $customer, Collection $accounts, array $state, string $input, ?string $transactionId): array
    {
        if ($input === '2') {
            return $this->end('Withdrawal cancelled.');
        }

        if ($input !== '1') {
            return $this->prompt("1. Confirm\n2. Cancel", $state);
        }

        $account = $accounts->firstWhere('id', $state['account_id'] ?? null);

        if ($account === null || $transactionId === null) {
            return $this->end('That withdrawal can no longer be completed.', ['status' => 'failed']);
        }

        try {
            $withdrawal = $this->requestWithdrawal->execute(
                $customer->user,
                $account,
                (int) $state['amount'],
                'Requested by USSD',
                $transactionId,
            );
        } catch (ValidationException $exception) {
            return $this->end((string) collect($exception->errors())->flatten()->first(), ['status' => 'failed']);
        }

        return $this->end(
            'Withdrawal request of '.Money::format($withdrawal->amount).' submitted. Your agent will contact you to pay out.',
            ['status' => 'pending', 'reference' => (string) $withdrawal->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function askAmount(SavingsAccount $account, ?string $notice = null): array
    {
        return $this->prompt(
            ltrim($notice."\nEnter amount to withdraw (GHS)"),
            ['step' => 'amount', 'account_id' => $account->id],
        );
    }

    /**
     * Balance less withdrawals already pending or approved, as RequestWithdrawalAction computes it.
     */
    private function availableBalance(SavingsAccount $account): int
    {
        $held = (int) $account->withdrawalRequests()
            ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])
            ->sum('amount');

        return max(0, $account->balance - $held);
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customers(string $msisdn, ?string $tenantRef): Collection
    {
        $digits = $this->phoneMatchDigits($msisdn);

        if ($digits === null) {
            return collect();
        }

        return Customer::query()
            ->with(['company', 'user'])
            ->where('status', AccountStatus::Active)
            ->where('phone', 'like', '%'.substr($digits, -4))
            ->when($tenantRef !== null, fn ($query) => $query->where('company_id', $tenantRef))
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->get()
            ->filter(fn (Customer $customer): bool => $this->phoneMatchDigits((string) $customer->phone) === $digits)
            ->values();
    }

    /**
     * @return Collection<int, SavingsAccount>
     */
    private function activeAccounts(Customer $customer): Collection
    {
        return $customer->savingsAccounts()
            ->with('product')
            ->where('status', AccountStatus::Active)
            ->orderBy('opened_at')
            ->get();
    }

    private function accountLabel(SavingsAccount $account): string
    {
        return $account->product?->name ?? (string) $account->account_number;
    }

    private function phoneMatchDigits(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return strlen($digits) < self::PHONE_MATCH_DIGITS ? null : substr($digits, -self::PHONE_MATCH_DIGITS);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function prompt(string $message, array $state): array
    {
        return ['message' => $message, 'continue' => true, 'state' => $state];
    }

    /**
     * @param  ?array{status: string, reference?: string}  $transaction
     * @return array<string, mixed>
     */
    private function end(string $message, ?array $transaction = null): array
    {
        return array_filter([
            'message' => $message,
            'continue' => false,
            'transaction' => $transaction,
        ], fn (mixed $value): bool => $value !== null);
    }
}
