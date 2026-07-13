<?php

namespace App\Services\Loans;

use App\Enums\AccountStatus;
use App\Enums\TransactionType;
use App\Models\SavingsAccount;

/**
 * Susu-history-based loan eligibility (plan AD: "account age, consistency %,
 * avg balance"). Thresholds are deliberately simple constants for now —
 * tune here, not scattered through Actions, once real portfolio data
 * suggests better numbers.
 */
class EligibilityService
{
    private const MIN_ACCOUNT_AGE_DAYS = 60;

    private const MIN_COLLECTIONS_COUNT = 20;

    /** A customer may borrow at most this many times their current savings balance. */
    private const MAX_LOAN_TO_BALANCE_MULTIPLE = 3;

    public function evaluate(SavingsAccount $account, int $requestedAmount): LoanEligibilityResult
    {
        $reasons = [];

        if ($account->status === AccountStatus::Closed) {
            $reasons[] = 'The savings account is closed.';
        }

        $ageDays = $account->opened_at->diffInDays(now());
        if ($ageDays < self::MIN_ACCOUNT_AGE_DAYS) {
            $reasons[] = sprintf(
                'Account must be at least %d days old (currently %d).',
                self::MIN_ACCOUNT_AGE_DAYS,
                $ageDays,
            );
        }

        $collectionsCount = $account->entries()->where('type', TransactionType::Collection)->count();
        if ($collectionsCount < self::MIN_COLLECTIONS_COUNT) {
            $reasons[] = sprintf(
                'At least %d prior collections are required (currently %d).',
                self::MIN_COLLECTIONS_COUNT,
                $collectionsCount,
            );
        }

        $maxLoanAmount = $account->balance * self::MAX_LOAN_TO_BALANCE_MULTIPLE;
        if ($requestedAmount > $maxLoanAmount) {
            $reasons[] = sprintf(
                'Requested amount exceeds %dx the account\'s current balance.',
                self::MAX_LOAN_TO_BALANCE_MULTIPLE,
            );
        }

        return new LoanEligibilityResult(eligible: $reasons === [], reasons: $reasons);
    }
}
