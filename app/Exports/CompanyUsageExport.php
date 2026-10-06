<?php

namespace App\Exports;

use App\Actions\Reports\BuildCompanyUsageReportAction;
use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CompanyUsageExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        return app(BuildCompanyUsageReportAction::class)->execute($this->from, $this->to);
    }

    public function headings(): array
    {
        return [
            'Company', 'Status', 'Branches', 'Staff', 'Active Admins', 'Customers', 'New Customers',
            'Active Savings Accounts', 'Savings Held', 'Loans Outstanding', 'Collections', 'Collections Amount',
            'Last Transaction', 'Onboarded',
        ];
    }

    /**
     * @param  Company  $company
     */
    public function map($company): array
    {
        return [
            $company->name,
            $company->is_active ? 'Active' : 'Suspended',
            $company->branches_count,
            $company->staff_count,
            $company->company_admins_count,
            $company->customers_count,
            $company->new_customers_count,
            $company->active_savings_accounts_count,
            Money::format((int) $company->savings_balance),
            Money::format((int) $company->loans_outstanding),
            $company->collections_count,
            Money::format((int) $company->collections_amount),
            $company->last_activity_at ? CarbonImmutable::parse($company->last_activity_at)->format('Y-m-d H:i') : 'Never',
            $company->created_at?->format('Y-m-d'),
        ];
    }
}
