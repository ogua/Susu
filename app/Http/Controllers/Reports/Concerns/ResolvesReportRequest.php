<?php

namespace App\Http\Controllers\Reports\Concerns;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Shared guards for the branch report endpoints: back-office roles only,
 * scoped to the caller's own company, with an optional from/to date range.
 */
trait ResolvesReportRequest
{
    private function authorizeStaffAccess(Request $request, Branch $branch): void
    {
        // Signed URLs (minted by the API after its own role checks, validated
        // by the 'signed' route middleware) carry no session user.
        if ($request->hasValidSignature()) {
            return;
        }

        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($user->hasRole(['company_admin', 'branch_manager']), 403);
        abort_unless($user->company_id === $branch->company_id, 404);
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : null,
            isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : null,
        ];
    }
}
