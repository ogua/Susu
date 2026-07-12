<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JournalEntryResource;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->customerFor($request);

        return SavingsAccountResource::collection(
            $customer->savingsAccounts()->with('product')->get()
        );
    }

    public function transactions(Request $request, SavingsAccount $account): AnonymousResourceCollection
    {
        $customer = $this->customerFor($request);
        abort_unless($account->customer_id === $customer->id, 404);

        $entries = JournalEntry::query()
            ->whereHas('lines', fn ($q) => $q->where('ledger_account_id', $account->ledger_account_id))
            ->orderByDesc('recorded_at')
            ->paginate($request->integer('per_page', 30));

        return JournalEntryResource::collection($entries);
    }

    protected function customerFor(Request $request): Customer
    {
        return Customer::where('user_id', $request->user()->id)->firstOrFail();
    }
}
