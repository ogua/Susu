<?php

namespace App\Actions\Payments;

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentMethod;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /** Polls Paystack directly — used by the client's fast verify screen. */
    public function execute(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status->isTerminal()) {
            return $intent;
        }

        $response = $this->paystack->verify($intent->provider_reference ?? $intent->client_reference);
        $data = $response['data'] ?? [];

        return $this->complete($intent, PaymentIntentStatus::fromProviderStatus($data['status'] ?? 'pending'), $response);
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
                $result = $this->recordCollection->execute(
                    agent: $locked->initiatedBy,
                    account: $account,
                    amount: $locked->amount,
                    clientReference: $locked->client_reference,
                    origin: ClientOrigin::System,
                    paymentMethod: PaymentMethod::MobileMoney,
                );

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
