<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordLoanRepaymentRequest;
use App\Http\Requests\Api\V1\StoreLoanApplicationRequest;
use App\Http\Resources\V1\LoanProductResource;
use App\Http\Resources\V1\LoanResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Loans\EligibilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Shared by agent (apply/repay on a customer's behalf) and customer
 * (self-service apply) roles — mirrors PaymentController's shared-endpoint
 * pattern. Which loans a caller may see/act on is enforced per-method below;
 * repayments additionally require a staff role at the route level.
 */
class LoanController extends Controller
{
    public function products(Request $request): AnonymousResourceCollection
    {
        return LoanProductResource::collection(
            LoanProduct::where('company_id', $request->user()->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function eligibility(Request $request, EligibilityService $eligibility): JsonResponse
    {
        $validated = $request->validate([
            'savings_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $account = SavingsAccount::where('company_id', $request->user()->company_id)
            ->findOrFail($validated['savings_account_id']);

        $result = $eligibility->evaluate($account, (int) $validated['amount']);

        return response()->json(['eligible' => $result->eligible, 'reasons' => $result->reasons]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Loan::with('loanProduct')->where('company_id', $user->company_id);
        $query = $user->hasRole('customer')
            ? $query->where('customer_id', $this->customerFor($user)->id)
            : $query->latest();

        return LoanResource::collection($query->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $loan): LoanResource
    {
        $model = $this->findScoped($request, $loan);

        return LoanResource::make($model->load('loanProduct', 'installments'));
    }

    public function store(StoreLoanApplicationRequest $request, ApplyForLoanAction $action): JsonResponse
    {
        $user = $request->user();
        $customer = $user->hasRole('customer')
            ? $this->customerFor($user)
            : Customer::where('company_id', $user->company_id)->findOrFail($request->validated('customer_id'));

        $product = LoanProduct::where('company_id', $user->company_id)->findOrFail($request->validated('loan_product_id'));
        $savingsAccount = $request->filled('savings_account_id')
            ? SavingsAccount::where('company_id', $user->company_id)->find($request->validated('savings_account_id'))
            : null;

        $loan = $action->execute(
            submittedBy: $user,
            customer: $customer,
            product: $product,
            requestedAmount: (int) $request->validated('amount'),
            savingsAccount: $savingsAccount,
            guarantorName: $request->validated('guarantor_name'),
            guarantorPhone: $request->validated('guarantor_phone'),
            notes: $request->validated('notes'),
            clientReference: $request->validated('client_reference'),
        );

        return LoanResource::make($loan)->response()->setStatusCode(201);
    }

    public function recordRepayment(RecordLoanRepaymentRequest $request, string $loan, RecordLoanRepaymentAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $loan);

        $result = $action->execute(
            loan: $model,
            amount: (int) $request->validated('amount'),
            recordedBy: $request->user(),
            clientReference: $request->validated('client_reference'),
            recordedAt: $request->filled('recorded_at') ? Carbon::parse($request->validated('recorded_at')) : null,
            origin: ClientOrigin::Mobile,
        );

        return response()->json([
            'entry_id' => $result->entry->id,
            'loan' => LoanResource::make($result->loan->load('installments')),
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 201);
    }

    private function findScoped(Request $request, string $id): Loan
    {
        $user = $request->user();
        $query = Loan::where('company_id', $user->company_id);

        if ($user->hasRole('customer')) {
            $query->where('customer_id', $this->customerFor($user)->id);
        }

        return $query->findOrFail($id);
    }

    private function customerFor(User $user): Customer
    {
        return Customer::where('user_id', $user->id)->firstOrFail();
    }
}
