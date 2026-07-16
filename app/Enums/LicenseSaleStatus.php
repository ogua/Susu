<?php

namespace App\Enums;

enum LicenseSaleStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Issued = 'issued';
    case Failed = 'failed';
}
