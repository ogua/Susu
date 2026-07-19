<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordGroupLoanRepaymentRequest;
use App\Http\Requests\Api\V1\StoreGroupLoanApplicationRequest;
use App\Http\Resources\V1\GroupLoanResource;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
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

        $query = GroupLoan::with('loanGroup', 'loanProduct')
            ->where('company_id', $user->company_id)
            ->where('branch_id', $user->branch_id);

        return GroupLoanResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $groupLoan): GroupLoanResource
    {
        $model = $this->findScoped($request, $groupLoan);

        return GroupLoanResource::make($model->load('loanGroup', 'loanProduct', 'borrowers.customer', 'installments'));
    }

    public function store(StoreGroupLoanApplicationRequest $request, ApplyForGroupLoanAction $action): JsonResponse
    {
        $user = $request->user();

        $loanGroup = LoanGroup::where('company_id', $user->company_id)->findOrFail($request->validated('loan_group_id'));
        $product = LoanProduct::where('company_id', $user->company_id)->findOrFail($request->validated('loan_product_id'));

        $groupLoan = $action->execute(
            submittedBy: $user,
            loanGroup: $loanGroup,
            product: $product,
            requestedAmount: (int) $request->validated('amount'),
            notes: $request->validated('notes'),
            clientReference: $request->validated('client_reference'),
        );

        return GroupLoanResource::make($groupLoan)->response()->setStatusCode(201);
    }

    public function recordRepayment(RecordGroupLoanRepaymentRequest $request, string $groupLoan, RecordGroupLoanRepaymentAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $groupLoan);
        $borrower = GroupLoanBorrower::where('group_loan_id', $model->id)->findOrFail($request->validated('group_loan_borrower_id'));

        $result = $action->execute(
            borrower: $borrower,
            amount: (int) $request->validated('amount'),
            recordedBy: $request->user(),
            clientReference: $request->validated('client_reference'),
            recordedAt: $request->filled('recorded_at') ? Carbon::parse($request->validated('recorded_at')) : null,
            origin: ClientOrigin::Mobile,
        );

        return response()->json([
            'entry_id' => $result->entry->id,
            'group_loan' => GroupLoanResource::make($result->groupLoan->load('installments', 'borrowers.customer')),
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 201);
    }

    private function findScoped(Request $request, string $id): GroupLoan
    {
        return GroupLoan::where('company_id', $request->user()->company_id)->findOrFail($id);
    }
}
