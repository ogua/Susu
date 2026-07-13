<?php

namespace App\Actions\Payments\Concerns;

use App\Models\SavingsAccount;
use App\Models\User;

/**
 * Paystack requires an email; susu customers rarely have one, so a stable
 * synthetic one is used instead. Confirmed against the live sandbox that
 * Paystack's email validator rejects RFC 2606 "never-resolves" TLDs like
 * .invalid — it wants something that at least looks like a real domain,
 * even though nothing is ever sent there.
 */
trait ResolvesCustomerEmail
{
    private function emailFor(SavingsAccount $account, User $fallbackUser): string
    {
        $account->loadMissing('customer');
        $phone = $account->customer?->phone ?? $fallbackUser->phone ?? $account->id;
        $slug = preg_replace('/[^a-z0-9]+/i', '', $phone);

        return "{$slug}@customers.susuapp.com";
    }
}
