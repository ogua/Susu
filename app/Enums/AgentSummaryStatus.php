<?php

namespace App\Enums;

enum AgentSummaryStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Reconciled = 'reconciled';
    case Flagged = 'flagged';
}
