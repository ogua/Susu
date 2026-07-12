<?php

namespace App\Enums;

enum CommissionType: string
{
    /** Ghanaian susu convention: the first contribution of every cycle is the fee. */
    case FirstContributionPerCycle = 'first_contribution_per_cycle';

    /** commission_value = basis points of each deposit. */
    case Percentage = 'percentage';

    /** commission_value = flat pesewas charged once per cycle started. */
    case FlatPerCycle = 'flat_per_cycle';
}
