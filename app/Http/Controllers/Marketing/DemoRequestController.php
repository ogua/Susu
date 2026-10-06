<?php

namespace App\Http\Controllers\Marketing;

use App\Actions\Marketing\SubmitDemoRequestAction;
use App\Enums\DemoOrganisationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreDemoRequestRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DemoRequestController extends Controller
{
    public function show(): View
    {
        return view('marketing.demo', [
            'organisationTypes' => DemoOrganisationType::cases(),
            'formStarted' => encrypt(now()->timestamp),
        ]);
    }

    public function store(StoreDemoRequestRequest $request, SubmitDemoRequestAction $submitDemoRequest): RedirectResponse
    {
        // Automated submissions get the same thank-you, but nothing is stored or sent.
        if (! $request->looksAutomated()) {
            $submitDemoRequest->execute($request->validated(), $request->ip());
        }

        return redirect()->route('marketing.demo')->with('demoRequestSent', true);
    }
}
