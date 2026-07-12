<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Resources\V1\JournalEntryResource;
use App\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    public function store(StoreCollectionRequest $request, RecordCollectionAction $action): JsonResponse
    {
        $account = SavingsAccount::where('company_id', $request->user()->company_id)
            ->findOrFail($request->validated('savings_account_id'));

        $result = $action->execute(
            agent: $request->user(),
            account: $account,
            amount: (int) $request->validated('amount'),
            clientReference: $request->validated('client_reference'),
            recordedAt: $request->filled('recorded_at') ? Carbon::parse($request->validated('recorded_at')) : null,
            origin: ClientOrigin::Mobile,
            latitude: $request->validated('latitude'),
            longitude: $request->validated('longitude'),
        );

        return response()->json([
            'entry' => JournalEntryResource::make($result->entry),
            'balance' => $result->account->balance,
            'commission' => $result->commissionAmount,
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 201);
    }
}
