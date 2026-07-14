<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Models\SavingsAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class AccountController extends Controller
{
    /** Accounts assigned to the authenticated agent, searchable by name/number/phone. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = SavingsAccount::query()
            ->where('agent_id', $request->user()->id)
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

    public function statement(Request $request, SavingsAccount $account): Response
    {
        abort_unless($account->company_id === $request->user()->company_id, 404);

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
        abort_unless($account->company_id === $request->user()->company_id, 404);

        return response()->json([
            'url' => URL::temporarySignedRoute('statements.signed', now()->addMinutes(5), ['account' => $account->id]),
        ]);
    }
}
