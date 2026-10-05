<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JournalEntryResource;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SavingsAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

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
            // Only this account's own lines: JournalEntryResource derives the
            // entry's direction (money in/out of this account) from them.
            ->with(['lines' => fn ($q) => $q->where('ledger_account_id', $account->ledger_account_id)])
            ->orderByDesc('recorded_at')
            ->paginate($request->integer('per_page', 30));

        return JournalEntryResource::collection($entries);
    }

    public function statement(Request $request, SavingsAccount $account): Response
    {
        $customer = $this->customerFor($request);
        abort_unless($account->customer_id === $customer->id, 404);

        $pdf = app(GenerateAccountStatementPdfAction::class)->execute(
            $account,
            $request->date('from'),
            $request->date('to'),
        );

        return $pdf->download("statement-{$account->account_number}.pdf");
    }

    /**
     * A short-lived signed URL the mobile app can open directly in its
     * in-app browser (no Authorization header support there) — see
     * SignedAccountStatementController.
     */
    public function statementUrl(Request $request, SavingsAccount $account): JsonResponse
    {
        $customer = $this->customerFor($request);
        abort_unless($account->customer_id === $customer->id, 404);

        return response()->json([
            'url' => URL::temporarySignedRoute('statements.signed', now()->addMinutes(5), ['account' => $account->id]),
        ]);
    }

    protected function customerFor(Request $request): Customer
    {
        return Customer::where('user_id', $request->user()->id)->firstOrFail();
    }
}
