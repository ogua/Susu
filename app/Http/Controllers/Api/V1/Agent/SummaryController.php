<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Agents\SubmitAgentDailySummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitDailySummaryRequest;
use App\Http\Resources\V1\AgentDailySummaryResource;
use App\Models\AgentDailySummary;
use App\Services\Ledger\ChartOfAccounts;
use App\Support\StaffBranch;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    public function today(Request $request, ChartOfAccounts $chart): JsonResponse
    {
        $summary = AgentDailySummary::firstOrCreate(
            ['agent_id' => $request->user()->id, 'summary_date' => now()->toDateString()],
            [
                'company_id' => $request->user()->company_id,
                'branch_id' => StaffBranch::resolve($request->user())->id,
            ],
        );

        // A freshly inserted row lacks the table's column defaults (zero totals).
        if ($summary->wasRecentlyCreated) {
            $summary->refresh();
        }

        return response()->json([
            'summary' => AgentDailySummaryResource::make($summary),
            'cash_in_hand' => $chart->agentCash($request->user())->refresh()->balance,
        ]);
    }

    public function store(SubmitDailySummaryRequest $request, SubmitAgentDailySummaryAction $action): AgentDailySummaryResource
    {
        $summary = $action->execute(
            agent: $request->user(),
            declaredCash: (int) $request->validated('declared_cash'),
            date: $request->filled('summary_date') ? Carbon::parse($request->validated('summary_date')) : null,
            notes: $request->validated('notes'),
            clientReference: $request->validated('client_reference'),
        );

        return AgentDailySummaryResource::make($summary);
    }
}
