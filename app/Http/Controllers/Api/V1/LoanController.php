<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\LoanApplicationDetails;
use App\Actions\Loans\RecalculateRepaymentScheduleAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Enums\ClientOrigin;
use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Http\Controllers\Api\V1\Concerns\ScopesToAccessibleBranches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecalculateScheduleRequest;
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
use App\Services\Loans\LoanCalculator;
use App\Support\AgentAssignment;
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
    use ScopesToAccessibleBranches;

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

        $account = $this->scopeToBranches(SavingsAccount::where('company_id', $request->user()->company_id), $request->user())
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
            : $this->scopeToBranches($query, $user)
                ->when(AgentAssignment::restricts($user), fn ($q) => AgentAssignment::scopeLoans($q, $user))
                ->latest();

        return LoanResource::collection($query->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $loan): LoanResource
    {
        $model = $this->findScoped($request, $loan);

        return LoanResource::make($model->load('loanProduct', 'installments', 'charges', 'collaterals', 'guarantors'));
    }

    public function store(StoreLoanApplicationRequest $request, ApplyForLoanAction $action): JsonResponse
    {
        $user = $request->user();
        $customer = $user->hasRole('customer')
            ? $this->customerFor($user)
            : $this->scopeToBranches(Customer::where('company_id', $user->company_id), $user)->findOrFail($request->validated('customer_id'));

        $product = LoanProduct::where('company_id', $user->company_id)->findOrFail($request->validated('loan_product_id'));
        $savingsAccount = $request->filled('savings_account_id')
            ? $this->scopeToBranches(SavingsAccount::where('company_id', $user->company_id), $user)->find($request->validated('savings_account_id'))
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
            details: LoanApplicationDetails::fromArray($request->validated()),
        );

        return LoanResource::make($loan->load('charges', 'collaterals', 'guarantors'))->response()->setStatusCode(201);
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

    public function recalculateSchedule(RecalculateScheduleRequest $request, string $loan, RecalculateRepaymentScheduleAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $loan);

        $result = $action->execute(
            $model,
            $request->user(),
            $request->filled('first_due_date') ? Carbon::parse($request->validated('first_due_date')) : null,
            $request->validated('reason'),
        );

        return LoanResource::make($model->fresh()->load('loanProduct', 'installments'))
            ->additional(['recalculation' => $result])
            ->response();
    }

    /** What-if repayment schedule (the "loan terms for repayment calculation" calculator). */
    public function calculate(Request $request, LoanCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'principal_amount' => ['required', 'integer', 'min:1'],
            'interest_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'interest_method' => ['required', 'string', 'in:flat,reducing_balance'],
            'term_period_count' => ['required', 'integer', 'min:1', 'max:520'],
            'repayment_frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'disbursement_date' => ['nullable', 'date'],
            'first_repayment_date' => ['nullable', 'date'],
            'charges' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $calculator->calculate(
            (int) $data['principal_amount'],
            (int) $data['interest_rate_bps'],
            (int) $data['term_period_count'],
            InterestMethod::from($data['interest_method']),
            LoanFrequency::from($data['repayment_frequency']),
            Carbon::parse($data['disbursement_date'] ?? now()),
            isset($data['first_repayment_date']) ? Carbon::parse($data['first_repayment_date']) : null,
            (int) ($data['charges'] ?? 0),
        )]);
    }

    private function findScoped(Request $request, string $id): Loan
    {
        $user = $request->user();
        $query = $this->scopeToBranches(Loan::where('company_id', $user->company_id), $user);

        if ($user->hasRole('customer')) {
            $query->where('customer_id', $this->customerFor($user)->id);
        } elseif (AgentAssignment::restricts($user)) {
            AgentAssignment::scopeLoans($query, $user);
        }

        return $query->findOrFail($id);
    }

    private function customerFor(User $user): Customer
    {
        return Customer::where('user_id', $user->id)->firstOrFail();
    }
}
