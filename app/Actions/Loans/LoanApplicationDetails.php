<?php

namespace App\Actions\Loans;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Illuminate\Support\Carbon;

/**
 * Everything the loan application wizard can add on top of the product
 * defaults: term/setting overrides, the first repayment date, itemised
 * charges, collateral and guarantors. Every field is optional — anything left
 * null falls back to the product, so existing API/sync clients that send none
 * of it keep getting exactly the old behaviour. Amounts are pesewas.
 */
final readonly class LoanApplicationDetails
{
    /**
     * @param  list<array{name: string, amount: int}>|null  $charges  null = the product's origination fee
     * @param  list<array{type: string, description: string, estimated_value?: ?int, serial_number?: ?string, notes?: ?string}>  $collaterals
     * @param  list<array{name: string, customer_id?: ?string, phone?: ?string, relationship?: ?string, address?: ?string, id_type?: ?string, id_number?: ?string, guaranteed_amount?: ?int}>  $guarantors
     */
    public function __construct(
        public ?int $termPeriodCount = null,
        public ?LoanFrequency $repaymentFrequency = null,
        public ?int $interestRateBps = null,
        public ?InterestMethod $interestMethod = null,
        public ?int $gracePeriodDays = null,
        public ?Carbon $firstRepaymentDate = null,
        public ?string $purpose = null,
        public ?array $charges = null,
        public array $collaterals = [],
        public array $guarantors = [],
    ) {}

    /**
     * Builds from validated request/sync/form input (see
     * StoreLoanApplicationRequest::detailRules()).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            termPeriodCount: isset($data['term_period_count']) ? (int) $data['term_period_count'] : null,
            repaymentFrequency: isset($data['repayment_frequency']) ? LoanFrequency::from($data['repayment_frequency']) : null,
            interestRateBps: isset($data['interest_rate_bps']) ? (int) $data['interest_rate_bps'] : null,
            interestMethod: isset($data['interest_method']) ? InterestMethod::from($data['interest_method']) : null,
            gracePeriodDays: isset($data['grace_period_days']) ? (int) $data['grace_period_days'] : null,
            firstRepaymentDate: filled($data['first_repayment_date'] ?? null) ? Carbon::parse($data['first_repayment_date'])->startOfDay() : null,
            purpose: filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null,
            charges: isset($data['charges']) ? array_values(array_map(fn (array $charge): array => [
                'name' => (string) $charge['name'],
                'amount' => (int) $charge['amount'],
            ], $data['charges'])) : null,
            collaterals: array_values($data['collaterals'] ?? []),
            guarantors: array_values($data['guarantors'] ?? []),
        );
    }

    /** Whether any loan term differs from what the product would give. */
    public function overridesTerms(): bool
    {
        return $this->termPeriodCount !== null
            || $this->repaymentFrequency !== null
            || $this->interestRateBps !== null
            || $this->interestMethod !== null
            || $this->gracePeriodDays !== null
            || $this->charges !== null;
    }
}
