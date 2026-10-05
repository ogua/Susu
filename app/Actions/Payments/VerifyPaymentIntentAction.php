<?php

namespace App\Actions\Payments;

use App\Actions\Savings\BuySharesAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentMethod;
use App\Enums\SavingsProductType;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a payment intent and posts the ledger entry exactly once. Called
 * from two independent races — the client's fast poll (execute()) and
 * Paystack's webhook (complete(), via HandlePaystackWebhookAction) — whichever
 * wins is fine: this method locks the row, and the underlying ledger post is
 * itself idempotent on client_reference, so the loser is always a safe no-op.
 */
class VerifyPaymentIntentAction
{
    public function __construct(
        private PaystackClient $paystack,
        private RecordCollectionAction $recordCollection,
        private BuySharesAction $buyShares,
    ) {}

    /** Polls Paystack directly — used by the client's fast verify screen. */
    public function execute(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status->isTerminal()) {
            return $intent;
        }

        $response = $this->paystack->forIntent($intent)->verify($intent->provider_reference ?? $intent->client_reference);
        $data = $response['data'] ?? [];

        return $this->complete($intent, PaymentIntentStatus::fromProviderStatus($data['status'] ?? 'pending'), $response);
    }

    /**
     * The same rules complete() applies when crediting the account, run
     * before Paystack is contacted. Without this a payment the ledger would
     * refuse (wrong amount, closed account, unassigned agent) was charged to
     * the customer first and then never credited.
     */
    public function assertCreditable(User $initiatedBy, SavingsAccount $account, int $amount): void
    {
        $account->loadMissing(['product', 'customer']);

        if ($account->product->type === SavingsProductType::Shares) {
            $this->assertMultipleOfParValue($account, $amount);
            $this->buyShares->assertPurchasable($initiatedBy, $account, intdiv($amount, $account->product->par_value));

            return;
        }

        $this->recordCollection->assertRecordable($initiatedBy, $account, $amount);
    }

    private function assertMultipleOfParValue(SavingsAccount $account, int $amount): void
    {
        if ($amount <= 0 || $amount % $account->product->par_value !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a positive multiple of the par value ('.$account->product->par_value.').',
            ]);
        }
    }

    /** Applies an already-known outcome (e.g. from a webhook payload) without calling Paystack again. */
    public function complete(PaymentIntent $intent, PaymentIntentStatus $status, array $rawResponse): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $status, $rawResponse): PaymentIntent {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            if ($status !== PaymentIntentStatus::Success) {
                $locked->forceFill(['status' => $status, 'raw_response' => $rawResponse])->save();

                return $locked;
            }

            $account = $locked->payable;
            if ($account instanceof SavingsAccount) {
                $account->loadMissing('product');

                if ($account->product->type === SavingsProductType::Shares) {
                    $this->assertMultipleOfParValue($account, $locked->amount);

                    $result = $this->buyShares->execute(
                        agent: $locked->initiatedBy,
                        account: $account,
                        shares: intdiv($locked->amount, $account->product->par_value),
                        clientReference: $locked->client_reference,
                        origin: ClientOrigin::System,
                        paymentMethod: PaymentMethod::MobileMoney,
                    );
                } else {
                    $result = $this->recordCollection->execute(
                        agent: $locked->initiatedBy,
                        account: $account,
                        amount: $locked->amount,
                        clientReference: $locked->client_reference,
                        origin: ClientOrigin::System,
                        paymentMethod: PaymentMethod::MobileMoney,
                    );
                }

                $locked->forceFill([
                    'status' => PaymentIntentStatus::Success,
                    'journal_entry_id' => $result->entry->id,
                    'raw_response' => $rawResponse,
                ])->save();
            }

            return $locked;
        });
    }
}
