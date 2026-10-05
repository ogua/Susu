<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Savings\RequestWithdrawalAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\WithdrawalRequestResource;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WithdrawalRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->customerFor($request);

        return WithdrawalRequestResource::collection(
            WithdrawalRequest::where('customer_id', $customer->id)
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 30))
        );
    }

    public function store(Request $request, RequestWithdrawalAction $action): JsonResponse
    {
        $validated = $request->validate([
            'savings_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'client_reference' => ['nullable', 'uuid'],
        ]);

        $customer = $this->customerFor($request);
        $account = SavingsAccount::where('customer_id', $customer->id)
            ->findOrFail($validated['savings_account_id']);

        $withdrawal = $action->execute(
            $request->user(),
            $account,
            (int) $validated['amount'],
            $validated['reason'] ?? null,
            $validated['client_reference'] ?? null,
        );

        // A replayed client_reference returns the original request (200), not a new one.
        return WithdrawalRequestResource::make($withdrawal)
            ->response()
            ->setStatusCode($withdrawal->wasRecentlyCreated ? 201 : 200);
    }

    protected function customerFor(Request $request): Customer
    {
        return Customer::where('user_id', $request->user()->id)->firstOrFail();
    }
}
