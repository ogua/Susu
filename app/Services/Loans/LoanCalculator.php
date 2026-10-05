<?php

namespace App\Services\Loans;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * "Loan terms for repayment calculation": a what-if schedule built by the very
 * ScheduleGenerator that disbursement uses, so the preview a customer is shown
 * is exactly what they will be asked to pay. Nothing is persisted.
 */
class LoanCalculator
{
    public function __construct(private ScheduleGenerator $schedule) {}

    /**
     * @return array{installments: list<array{sequence: int, due_date: string, principal: int, interest: int, total: int, balance: int}>, total_interest: int, total_repayable: int, charges: int, net_disbursed: int, first_due_date: string, maturity_date: string}
     */
    public function calculate(
        int $principal,
        int $rateBasisPoints,
        int $termPeriods,
        InterestMethod $method,
        LoanFrequency $frequency,
        CarbonInterface $disbursementDate,
        ?CarbonInterface $firstDueDate = null,
        int $charges = 0,
    ): array {
        $installments = $this->schedule->generate(
            $principal,
            $rateBasisPoints,
            $termPeriods,
            $method,
            $frequency,
            Carbon::parse($disbursementDate),
            $firstDueDate !== null ? Carbon::parse($firstDueDate) : null,
        );

        $balance = $principal;
        $rows = [];
        foreach ($installments as $installment) {
            $balance -= $installment->principalDue;
            $rows[] = [
                'sequence' => $installment->sequence,
                'due_date' => $installment->dueDate->toDateString(),
                'principal' => $installment->principalDue,
                'interest' => $installment->interestDue,
                'total' => $installment->principalDue + $installment->interestDue,
                'balance' => $balance,
            ];
        }

        $totalInterest = array_sum(array_column($rows, 'interest'));

        return [
            'installments' => $rows,
            'total_interest' => $totalInterest,
            'total_repayable' => $principal + $totalInterest,
            'charges' => $charges,
            'net_disbursed' => $principal - $charges,
            'first_due_date' => $rows[0]['due_date'],
            'maturity_date' => $rows[count($rows) - 1]['due_date'],
        ];
    }
}
