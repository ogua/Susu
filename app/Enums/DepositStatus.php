<?php

namespace App\Enums;

/**
 * The state of a group loan member's security deposit.
 *
 * - Pending: agreed at issue time, not yet paid in.
 * - Held:    paid in and sitting on the books as a per-loan liability.
 * - Settled: no longer held — refunded in cash, applied against the loan
 *            balance, seized during a write-off, or some combination. The
 *            group_loan_deposits rows carry which.
 */
enum DepositStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Settled = 'settled';
}
