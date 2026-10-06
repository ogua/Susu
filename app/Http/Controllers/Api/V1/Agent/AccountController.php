<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Http\Controllers\Api\V1\Concerns\ScopesToAccessibleBranches;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\SavingsAccount;
use App\Support\AgentAssignment;
use App\Support\StaffBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class AccountController extends Controller
{
    use ScopesToAccessibleBranches;

    /**
     * Accounts the caller collects on, searchable by name/number/phone: a
     * field agent's own accounts, or every account in a manager's/admin's
     * branches (one branch with ?branch_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['branch_id' => ['nullable', 'uuid']]);
        $user = $request->user();
        $branchIds = $request->filled('branch_id') ? [StaffBranch::resolve($user, $request->query('branch_id'))->id] : null;

        $accounts = AgentAssignment::scopeAccounts(SavingsAccount::where('company_id', $user->company_id), $user, $branchIds)
            ->with(['customer', 'product'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->value().'%';
                $query->where(function ($q) use ($term): void {
                    $q->where('account_number', 'like', $term)
                        ->orWhereHas('customer', function ($c) use ($term): void {
                            $c->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->orderBy('account_number')
            ->paginate($request->integer('per_page', 50));

        return SavingsAccountResource::collection($accounts);
    }

    /** The assigned agent, or staff of the account's branch. */
    private function canAccessAccount(Request $request, SavingsAccount $account): bool
    {
        $user = $request->user();

        return $account->company_id === $user->company_id
            && ($account->agent_id === $user->id || $this->canSeeBranch($user, $account->branch_id));
    }

    public function statement(Request $request, SavingsAccount $account): Response
    {
        abort_unless($this->canAccessAccount($request, $account), 404);

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
        abort_unless($this->canAccessAccount($request, $account), 404);

        return response()->json([
            'url' => URL::temporarySignedRoute('statements.signed', now()->addMinutes(5), ['account' => $account->id]),
        ]);
    }
}
