<?php

namespace App\Actions\Company;

use App\Jobs\GenerateCompanyExportJob;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\Customer;
use App\Models\CustomerBeneficiary;
use App\Models\CustomerFamilyMember;
use App\Models\CustomerIdentification;
use App\Models\Group;
use App\Models\GroupContribution;
use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\GroupLoanInstallment;
use App\Models\GroupLoanRepayment;
use App\Models\GroupMember;
use App\Models\GroupRound;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanCharge;
use App\Models\LoanCollateral;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\LoanGuarantor;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\NotificationLog;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * A company's complete records as a zip of CSVs — for the company to take
 * away when it leaves (data portability) or for the operator's own archive.
 *
 * Rows are written through their models, so casts apply (encrypted ID
 * numbers come out readable, enums as values) and hidden attributes never
 * appear (passwords, tokens, authenticator secrets). Integration secrets
 * (SMS/Paystack keys) and device location pings are left out entirely.
 */
class ExportCompanyDataAction
{
    public function request(Company $company, ?User $requestedBy): CompanyExport
    {
        $export = $company->exports()->create([
            'requested_by' => $requestedBy?->id,
            'status' => CompanyExport::STATUS_PENDING,
        ]);

        GenerateCompanyExportJob::dispatch($export);

        return $export;
    }

    /** Builds the zip for a pending export (run by GenerateCompanyExportJob). */
    public function build(CompanyExport $export): CompanyExport
    {
        $company = $export->company;
        $path = "exports/companies/{$company->id}/{$company->slug}-".now()->format('Ymd-His').'.zip';
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $zipFile = $disk->path($path);
        $tempFiles = [];

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the export archive.');
            }

            foreach ($this->datasets($company) as $name => $query) {
                $tempFiles[] = $csv = tempnam(sys_get_temp_dir(), 'export');
                $this->writeCsv($query, $csv);
                $zip->addFile($csv, "{$name}.csv");
            }

            $zip->close();

            $export->update([
                'status' => CompanyExport::STATUS_READY,
                'path' => $path,
                'size_bytes' => $disk->size($path),
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => CompanyExport::STATUS_FAILED, 'error' => $e->getMessage()]);
        } finally {
            foreach ($tempFiles as $file) {
                @unlink($file);
            }
        }

        return $export;
    }

    /**
     * File name => query, every one scoped to the company.
     *
     * @return array<string, Builder<Model>>
     */
    public function datasets(Company $company): array
    {
        $scoped = fn (string $model): Builder => $model::query()->where('company_id', $company->id);
        $ids = fn (string $model): Builder => $scoped($model)->select('id');

        $customers = Customer::withTrashed()->where('company_id', $company->id)->select('id');
        $loans = $ids(Loan::class);
        $groups = $ids(Group::class);
        $loanGroups = $ids(LoanGroup::class);
        $groupLoans = $ids(GroupLoan::class);

        return [
            'company' => Company::query()->whereKey($company->id),
            'branches' => $scoped(Branch::class),
            'users' => $scoped(User::class),
            'customers' => Customer::withTrashed()->where('company_id', $company->id),
            'customer_identifications' => CustomerIdentification::query()->whereIn('customer_id', $customers),
            'customer_beneficiaries' => CustomerBeneficiary::query()->whereIn('customer_id', $customers),
            'customer_family_members' => CustomerFamilyMember::query()->whereIn('customer_id', $customers),
            'savings_products' => SavingsProduct::withTrashed()->where('company_id', $company->id),
            'savings_accounts' => $scoped(SavingsAccount::class),
            'withdrawal_requests' => $scoped(WithdrawalRequest::class),
            'loan_products' => LoanProduct::withTrashed()->where('company_id', $company->id),
            'loans' => $scoped(Loan::class),
            'loan_installments' => LoanInstallment::query()->whereIn('loan_id', $loans),
            'loan_charges' => LoanCharge::query()->whereIn('loan_id', $loans),
            'loan_guarantors' => LoanGuarantor::query()->whereIn('loan_id', $loans),
            'loan_collaterals' => LoanCollateral::query()->whereIn('loan_id', $loans),
            'susu_groups' => $scoped(Group::class),
            'susu_group_members' => GroupMember::query()->whereIn('group_id', $groups),
            'susu_group_rounds' => GroupRound::query()->whereIn('group_id', $groups),
            'susu_group_contributions' => GroupContribution::query()->whereIn('group_round_id', GroupRound::query()->whereIn('group_id', $groups)->select('id')),
            'loan_groups' => $scoped(LoanGroup::class),
            'loan_group_members' => LoanGroupMember::query()->whereIn('loan_group_id', $loanGroups),
            'group_loans' => $scoped(GroupLoan::class),
            'group_loan_installments' => GroupLoanInstallment::query()->whereIn('group_loan_id', $groupLoans),
            'group_loan_repayments' => GroupLoanRepayment::query()->whereIn('group_loan_id', $groupLoans),
            'group_loan_deposits' => GroupLoanDeposit::query()->whereIn('group_loan_id', $groupLoans),
            'ledger_accounts' => $scoped(LedgerAccount::class),
            'journal_entries' => $scoped(JournalEntry::class),
            'journal_lines' => JournalLine::query()->whereIn('journal_entry_id', $ids(JournalEntry::class)),
            'payment_intents' => $scoped(PaymentIntent::class),
            'agent_daily_summaries' => $scoped(AgentDailySummary::class),
            'notification_logs' => $scoped(NotificationLog::class),
            'subscription_invoices' => $scoped(SubscriptionInvoice::class),
        ];
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function writeCsv(Builder $query, string $file): void
    {
        $handle = fopen($file, 'w');
        $headerWritten = false;

        foreach ($query->lazyById(500) as $record) {
            $row = collect($record->attributesToArray())
                ->map(fn ($value): ?string => is_array($value) ? json_encode($value) : ($value === null ? null : (string) $value))
                ->all();

            if (! $headerWritten) {
                fputcsv($handle, array_keys($row));
                $headerWritten = true;
            }

            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
