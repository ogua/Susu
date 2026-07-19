<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LoanGroupResource;
use App\Models\LoanGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-only (field_agent/branch_manager/company_admin) — see routes/api/v1.php. */
class LoanGroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = LoanGroup::with('members.customer')
            ->where('company_id', $user->company_id)
            ->where('branch_id', $user->branch_id);

        return LoanGroupResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $loanGroup): LoanGroupResource
    {
        $model = LoanGroup::where('company_id', $request->user()->company_id)->findOrFail($loanGroup);

        return LoanGroupResource::make($model->load('members.customer'));
    }
}
