<?php

namespace App\Http\Controllers;

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SavingsAccountStatementController extends Controller
{
    public function __invoke(Request $request, SavingsAccount $account): Response
    {
        Gate::authorize('view', $account);

        $pdf = app(GenerateAccountStatementPdfAction::class)->execute(
            $account,
            $request->date('from'),
            $request->date('to'),
        );

        return $pdf->download("statement-{$account->account_number}.pdf");
    }
}
