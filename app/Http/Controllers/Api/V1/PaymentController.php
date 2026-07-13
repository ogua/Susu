<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\InitializeCheckoutAction;
use App\Actions\Payments\InitiateMobileMoneyChargeAction;
use App\Actions\Payments\SubmitChargeOtpAction;
use App\Actions\Payments\VerifyPaymentIntentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InitializeCheckoutRequest;
use App\Http\Requests\Api\V1\InitiateMobileMoneyChargeRequest;
use App\Http\Requests\Api\V1\SubmitChargeOtpRequest;
use App\Http\Resources\V1\PaymentIntentResource;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by agent and customer roles — both the collect screen (agent) and
 * the deposit screen (customer) drive the same intent lifecycle. Which
 * accounts a caller may act on is enforced inside RecordCollectionAction's
 * assertRecordable (assigned agent, manager, or the account's own customer).
 */
class PaymentController extends Controller
{
    public function chargeMobileMoney(
        InitiateMobileMoneyChargeRequest $request,
        InitiateMobileMoneyChargeAction $action,
    ): JsonResponse {
        $account = SavingsAccount::where('company_id', $request->user()->company_id)
            ->findOrFail($request->validated('savings_account_id'));

        $intent = $action->execute(
            initiatedBy: $request->user(),
            account: $account,
            amount: (int) $request->validated('amount'),
            phone: $request->validated('phone'),
            provider: $request->validated('provider'),
            clientReference: $request->validated('client_reference'),
        );

        return response()->json(['intent' => PaymentIntentResource::make($intent)], 201);
    }

    public function initializeCheckout(InitializeCheckoutRequest $request, InitializeCheckoutAction $action): JsonResponse
    {
        $account = SavingsAccount::where('company_id', $request->user()->company_id)
            ->findOrFail($request->validated('savings_account_id'));

        $result = $action->execute(
            initiatedBy: $request->user(),
            account: $account,
            amount: (int) $request->validated('amount'),
            callbackUrl: $request->validated('callback_url'),
            clientReference: $request->validated('client_reference'),
        );

        return response()->json([
            'intent' => PaymentIntentResource::make($result['intent']),
            'authorization_url' => $result['authorization_url'],
        ], 201);
    }

    public function submitOtp(SubmitChargeOtpRequest $request, string $intent, SubmitChargeOtpAction $action): JsonResponse
    {
        $paymentIntent = $this->findScoped($request, $intent);

        $updated = $action->execute($paymentIntent, $request->validated('otp'));

        return response()->json(['intent' => PaymentIntentResource::make($updated)]);
    }

    public function verify(Request $request, string $intent, VerifyPaymentIntentAction $action): JsonResponse
    {
        $paymentIntent = $this->findScoped($request, $intent);

        $updated = $action->execute($paymentIntent);

        return response()->json(['intent' => PaymentIntentResource::make($updated)]);
    }

    public function show(Request $request, string $intent): JsonResponse
    {
        $paymentIntent = $this->findScoped($request, $intent);

        return response()->json(['intent' => PaymentIntentResource::make($paymentIntent)]);
    }

    private function findScoped(Request $request, string $id): PaymentIntent
    {
        return PaymentIntent::where('company_id', $request->user()->company_id)->findOrFail($id);
    }
}
