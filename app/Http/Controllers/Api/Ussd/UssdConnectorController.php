<?php

namespace App\Http\Controllers\Api\Ussd;

use App\Http\Controllers\Controller;
use App\Services\Ussd\SusuUssdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connector for the central Ogua USSD platform. Requests are signed by the
 * platform (VerifyUssdSignature); replies are short USSD screens.
 *
 * The platform decides who may see what (PIN, auth levels, amount limits).
 * This side still re-checks that the calling phone number is the customer's,
 * so an account is never read or debited for a number that isn't theirs.
 */
class UssdConnectorController extends Controller
{
    public function __construct(private SusuUssdService $ussd) {}

    /**
     * POST /api/ussd/identify
     */
    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
            'tenant_ref' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'links' => $this->ussd->identify($data['msisdn'], $data['tenant_ref'] ?? null),
        ]);
    }

    /**
     * POST /api/ussd/tenants — the active Susu companies the platform can onboard.
     */
    public function tenants(): JsonResponse
    {
        return response()->json(['tenants' => $this->ussd->tenants()]);
    }

    /**
     * POST /api/ussd/actions/{key}
     */
    public function action(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'tenant_ref' => ['required', 'string', 'max:100'],
            'subject_ref' => ['nullable', 'string', 'max:100'],
            'msisdn' => ['required', 'string', 'max:20'],
            'request_id' => ['nullable', 'string', 'max:100'],
            'payload' => ['nullable', 'array'],
            'payload.input' => ['nullable', 'string', 'max:182'],
            'payload.state' => ['nullable', 'array'],
            'payload.transaction_id' => ['nullable', 'uuid'],
        ]);

        if (! in_array($key, ['susu.balance', 'susu.history', 'susu.loan_balance', 'susu.withdrawal', 'susu.contribute'], true)) {
            return response()->json(['message' => 'Unknown USSD action.'], 404);
        }

        $customer = filled($data['subject_ref'] ?? null)
            ? $this->ussd->findCustomer($data['msisdn'], $data['tenant_ref'], $data['subject_ref'])
            : null;

        if (! $customer) {
            return $this->reply('We could not find your account. Please contact your Susu office.');
        }

        $payload = $data['payload'] ?? [];

        return match ($key) {
            'susu.balance' => $this->reply($this->ussd->balance($customer)),
            'susu.history' => $this->reply($this->ussd->history($customer)),
            'susu.loan_balance' => $this->reply($this->ussd->loanBalance($customer)),
            'susu.contribute' => response()->json($this->ussd->contribute(
                $customer,
                $data['msisdn'],
                $payload['input'] ?? null,
                $payload['state'] ?? [],
                $payload['transaction_id'] ?? null,
            )),
            'susu.withdrawal' => response()->json($this->ussd->withdrawal(
                $customer,
                $payload['input'] ?? null,
                $payload['state'] ?? [],
                $payload['transaction_id'] ?? null,
            )),
        };
    }

    private function reply(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'continue' => false]);
    }
}
