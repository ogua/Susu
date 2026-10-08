<?php

namespace App\Jobs\Ussd;

use App\Actions\Payments\InitiateMobileMoneyChargeAction;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\UssdPaymentReport;
use App\Services\Ussd\UssdServiceUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sends the MoMo charge for a USSD contribution once the USSD session has closed —
 * a phone cannot show the MoMo approval prompt while its USSD session is still open,
 * so this is dispatched with a short delay after the final USSD screen.
 *
 * Idempotent on the platform's transaction id (the charge's client_reference). Not
 * retried: a retry would find the existing PaymentIntent and could never re-send it.
 */
class InitiateUssdContributionCharge implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $customerId,
        public string $savingsAccountId,
        public int $amount,
        public string $phone,
        public string $provider,
        public string $clientReference,
    ) {}

    public function handle(InitiateMobileMoneyChargeAction $charge, UssdServiceUser $serviceUser): void
    {
        $customer = Customer::query()->findOrFail($this->customerId);
        $account = SavingsAccount::query()->where('customer_id', $customer->id)->findOrFail($this->savingsAccountId);

        // Before charging: Paystack can settle the charge within the same call, and the
        // observer that reports the outcome back to the platform looks for this row.
        UssdPaymentReport::query()->firstOrCreate(['client_reference' => $this->clientReference]);

        try {
            $charge->execute(
                $serviceUser->initiatorFor($customer),
                $account,
                $this->amount,
                $this->phone,
                $this->provider,
                $this->clientReference,
            );
        } catch (ValidationException $exception) {
            Log::warning('USSD contribution charge refused', [
                'client_reference' => $this->clientReference,
                'errors' => $exception->errors(),
            ]);
        }
    }
}
