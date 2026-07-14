<?php

namespace App\Http\Controllers;

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Headerless PDF download for clients that can't attach an Authorization
 * header to a browser-opened URL (the mobile app's in-app browser). The
 * signature itself is the authorization: it's only ever minted by
 * Customer\AccountController::statementUrl() / Agent\AccountController::
 * statementUrl() after the normal Sanctum + ownership checks, and expires
 * in minutes (see those methods), so the URL is only ever useful for a
 * short window right after a legitimate request.
 */
class SignedAccountStatementController extends Controller
{
    public function __invoke(Request $request, SavingsAccount $account): Response
    {
        $pdf = app(GenerateAccountStatementPdfAction::class)->execute(
            $account,
            $request->date('from'),
            $request->date('to'),
        );

        return $pdf->download("statement-{$account->account_number}.pdf");
    }
}
