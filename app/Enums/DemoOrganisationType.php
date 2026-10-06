<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The kind of organisation asking for an OguaFinance demo on the public website. */
enum DemoOrganisationType: string implements HasLabel
{
    case SusuEnterprise = 'susu_enterprise';
    case Microfinance = 'microfinance';
    case SavingsAndLoans = 'savings_and_loans';
    case Cooperative = 'cooperative';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SusuEnterprise => 'Susu enterprise / collector',
            self::Microfinance => 'Microfinance institution',
            self::SavingsAndLoans => 'Savings & loans company',
            self::Cooperative => 'Cooperative / credit union',
            self::Other => 'Other',
        };
    }
}
