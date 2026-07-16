<?php

namespace App\Http\Controllers\License;

use App\Actions\License\FulfillLicenseSaleAction;
use App\Actions\License\InitiateLicenseCheckoutAction;
use App\Http\Controllers\Controller;
use App\Models\DesktopLicenseSale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Guest-facing "buy a desktop activation key" flow — the web presence the
 * desktop app's license screen links out to. No auth: a license purchase
 * has no Company/account context (see plan Phase 6).
 */
class LicenseCheckoutController extends Controller
{
    public function showActivationForm(?string $installId = null): View
    {
        return view('license.activate', [
            'installId' => $installId,
            'price' => config('license.price'),
            'currency' => config('license.currency'),
            'durationDays' => config('license.duration_days'),
        ]);
    }

    public function initiateCheckout(Request $request, InitiateLicenseCheckoutAction $action): RedirectResponse
    {
        $data = $request->validate([
            'install_id' => ['required', 'string', 'max:191'],
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_email' => ['required', 'email', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $action->execute(
            $data['install_id'],
            $data['customer_name'],
            $data['customer_email'],
            $data['customer_phone'] ?? null,
            URL::to('/license/callback'),
        );

        if ($result['authorization_url'] === null) {
            return back()->withErrors(['checkout' => 'Could not start the payment. Please try again.']);
        }

        return redirect()->away($result['authorization_url']);
    }

    public function handleCallback(Request $request, FulfillLicenseSaleAction $action): View
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $sale = $reference !== null
            ? DesktopLicenseSale::where('provider_reference', $reference)->first()
            : null;

        if ($sale === null) {
            return view('license.checkout-result', ['sale' => null]);
        }

        $sale = $action->execute($sale);

        return view('license.checkout-result', ['sale' => $sale]);
    }
}
