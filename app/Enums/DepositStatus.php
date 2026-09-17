<?php

namespace App\Enums;

/**
 * The state of a group loan member's security deposit.
 *
 * - Pending: agreed at issue time, not yet paid in.
 * - Held:    paid in — credited straight into the member's chosen savings
 *            account. There is no further transition: once paid in it stays
 *            Held for the life of the loan, purely informational (it only
 *            gates whether the "record deposit" action is still offered).
 */
enum DepositStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
}
