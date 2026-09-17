<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Enums\ClientOrigin;
use App\Enums\LoanFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActivateGroupLoanRequest;
use App\Http\Requests\Api\V1\IssueGroupMemberLoanRequest;
use App\Http\Requests\Api\V1\RecordGroupLoanDepositRequest;
use App\Http\Requests\Api\V1\RecordGroupLoanRepaymentRequest;
use App\Http\Requests\Api\V1\WriteOffGroupLoanRequest;
use App\Http\Resources\V1\GroupLoanResource;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-only (field_agent/branch_manager/company_admin) — see routes/api/v1.php. */
class GroupLoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = GroupLoan::with('loanGroup', 'customer', 'installments')
            ->where('company_id', $user->company_id)
            ->where('branch_id', $user->branch_id)
            ->when($request->filled('loan_group_id'), fn ($q) => $q->where('loan_group_id', $request->string('loan_group_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return GroupLoanResource::collection($query->latest('issued_at')->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $groupLoan): GroupLoanResource
    {
        $model = $this->findScoped($request, $groupLoan);

        return GroupLoanResource::make($model->load('loanGroup', 'customer', 'installments', 'repayments', 'deposits'));
    }

    public function store(IssueGroupMemberLoanRequest $request, IssueGroupMemberLoanAction $action): JsonResponse
    {
        $user = $request->user();

        $loanGroup = LoanGroup::where('company_id', $user->company_id)->findOrFail($request->validated('loan_group_id'));
        $customer = Customer::where('company_id', $user->company_id)->findOrFail($request->validated('customer_id'));

        $groupLoan = $action->execute(
            issuedBy: $user,
            loanGroup: $loanGroup,
            customer: $customer,
            principal: (int) $request->validated('principal_amount'),
            securityDeposit: (int) $request->validated('security_deposit_amount'),
            periodicAmount: (int) $request->validated('periodic_amount'),
            frequency: LoanFrequency::from($request->validated('repayment_frequency')),
            startDate: Carbon::parse($request->validated('start_date')),
            notes: $request->validated('notes'),
            clientReference: $request->validated('client_reference'),
        );

        return GroupLoanResource::make($groupLoan->load('loanGroup', 'customer'))->response()->setStatusCode(201);
    }

    public function recordDeposit(RecordGroupLoanDepositRequest $request, string $groupLoan, RecordGroupLoanDepositAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $groupLoan);
        $savingsAccount = SavingsAccount::where('company_id', $request->user()->company_id)
            ->findOrFail($request->validated('savings_account_id'));

        $result = $action->execute(
            groupLoan: $model,
            savingsAccount: $savingsAccount,
            amount: (int) $request->validated('amount'),
            recordedBy: $request->user(),
            clientReference: $request->validated('client_reference'),
            recordedAt: $request->filled('recorded_at') ? Carbon::parse($request->validated('recorded_at')) : null,
            origin: ClientOrigin::Mobile,
        );

        return response()->json([
            'entry_id' => $result->entry?->id,
            'group_loan' => GroupLoanResource::make($result->groupLoan->load('loanGroup', 'customer')),
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 201);
    }

    public function activate(ActivateGroupLoanRequest $request, string $groupLoan, ActivateGroupLoanAction $action): GroupLoanResource
    {
        $model = $this->findScoped($request, $groupLoan);

        $groupLoan = $action->execute($model, $request->user(), ClientOrigin::Mobile);

        return GroupLoanResource::make($groupLoan->load('loanGroup', 'customer', 'installments'));
    }

    public function recordRepayment(RecordGroupLoanRepaymentRequest $request, string $groupLoan, RecordGroupLoanRepaymentAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $groupLoan);

        $result = $action->execute(
            groupLoan: $model,
            amount: (int) $request->validated('amount'),
            recordedBy: $request->user(),
            clientReference: $request->validated('client_reference'),
            recordedAt: $request->filled('recorded_at') ? Carbon::parse($request->validated('recorded_at')) : null,
            origin: ClientOrigin::Mobile,
        );

        return response()->json([
            'entry_id' => $result->entry->id,
            'group_loan' => GroupLoanResource::make($result->groupLoan->load('loanGroup', 'customer', 'installments')),
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 201);
    }

    public function writeOff(WriteOffGroupLoanRequest $request, string $groupLoan, WriteOffGroupLoanAction $action): GroupLoanResource
    {
        $model = $this->findScoped($request, $groupLoan);
        $savingsAccount = $request->filled('savings_account_id')
            ? SavingsAccount::where('company_id', $request->user()->company_id)->findOrFail($request->validated('savings_account_id'))
            : null;

        $groupLoan = $action->execute(
            $model,
            $request->user(),
            $request->validated('reason'),
            savingsAccount: $savingsAccount,
            savingsAmountApplied: (int) $request->validated('savings_amount_applied', 0),
            origin: ClientOrigin::Mobile,
        );

        return GroupLoanResource::make($groupLoan->load('loanGroup', 'customer'));
    }

    private function findScoped(Request $request, string $id): GroupLoan
    {
        return GroupLoan::where('company_id', $request->user()->company_id)->findOrFail($id);
    }
}
