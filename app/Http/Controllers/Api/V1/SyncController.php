<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ProcessSyncBatchAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncBatchRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\Customer;
use App\Models\SavingsAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function batch(SyncBatchRequest $request, ProcessSyncBatchAction $action): JsonResponse
    {
        $origin = ClientOrigin::tryFrom($request->header('X-Client-Origin', 'mobile')) ?? ClientOrigin::Mobile;

        $results = $action->execute($request->user(), $origin, $request->validated('ops'));

        return response()->json(['results' => $results]);
    }

    /** Full snapshot of the caller's working set for the local database. */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = SavingsAccount::query()
            ->where('agent_id', $user->id)
            ->with('product')
            ->get();

        $customers = Customer::whereIn('id', $accounts->pluck('customer_id'))->get();

        return response()->json([
            'accounts' => SavingsAccountResource::collection($accounts),
            'customers' => CustomerResource::collection($customers),
            'cursor' => now()->toISOString(),
        ]);
    }

    /** Changes since the cursor, for incremental refreshes. */
    public function delta(Request $request): JsonResponse
    {
        $validated = $request->validate(['cursor' => ['required', 'date']]);
        $since = Carbon::parse($validated['cursor']);
        $user = $request->user();

        $accounts = SavingsAccount::query()
            ->where('agent_id', $user->id)
            ->where('updated_at', '>', $since)
            ->with('product')
            ->get();

        $customers = Customer::query()
            ->whereIn('id', SavingsAccount::where('agent_id', $user->id)->pluck('customer_id'))
            ->where('updated_at', '>', $since)
            ->get();

        return response()->json([
            'accounts' => SavingsAccountResource::collection($accounts),
            'customers' => CustomerResource::collection($customers),
            'cursor' => now()->toISOString(),
        ]);
    }
}
