<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reports\BuildGeneralLedgerAction;
use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chart of accounts + per-account entries for back-office clients (desktop
 * hybrid mode). Company-wide, mirroring the web resource.
 */
class LedgerController extends Controller
{
    public function accounts(Request $request): JsonResponse
    {
        $accounts = LedgerAccount::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return response()->json([
            'accounts' => $accounts->map(fn (LedgerAccount $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'normal_balance' => $account->type->normalBalance(),
                'branch_id' => $account->branch_id,
                'balance' => (int) $account->balance,
                'is_system' => (bool) $account->is_system,
            ])->all(),
        ]);
    }

    public function entries(Request $request, LedgerAccount $account): JsonResponse
    {
        abort_unless($account->company_id === $request->user()->company_id, 404);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : null;

        $result = app(BuildGeneralLedgerAction::class)
            ->execute($account->company, $account, $from, $to);

        return response()->json([
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
            ],
            'opening_balance' => $result['openingBalance'],
            'closing_balance' => $result['closingBalance'],
            'total_debits' => $result['totalDebits'],
            'total_credits' => $result['totalCredits'],
            'entries' => $result['rows']->map(fn (array $row): array => [
                'recorded_at' => $row['line']->entry->recorded_at->toIso8601String(),
                'reference' => $row['line']->entry->reference,
                'type' => $row['line']->entry->type->value,
                'description' => $row['line']->memo ?? $row['line']->entry->description,
                'debit' => (int) $row['line']->debit,
                'credit' => (int) $row['line']->credit,
                'running_balance' => $row['running'],
            ])->all(),
        ]);
    }
}
