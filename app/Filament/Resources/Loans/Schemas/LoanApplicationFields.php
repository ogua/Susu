<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Enums\AccountStatus;
use App\Enums\IdentificationType;
use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Services\Loans\LoanCalculator;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The individual loan application, eBanQR-style: Terms → Settings → Charges →
 * Collateral → Guarantors → Repayment schedule → Preview. Settings and charges
 * are pre-filled from the product and may be adjusted by staff. Money fields
 * are entered in GHS and dehydrated to pesewas.
 */
class LoanApplicationFields
{
    /**
     * @return array<int, Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('Terms')
                ->columns(2)
                ->schema([
                    Select::make('customer_id')
                        ->label('Client')
                        ->options(fn (): array => Customer::where('branch_id', Filament::getTenant()?->id)
                            ->where('status', AccountStatus::Active)
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->fullName().' ('.$customer->customer_code.')'])
                            ->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('savings_account_id', null))
                        ->required(),
                    Select::make('loan_product_id')
                        ->label('Loan product')
                        ->options(fn (): array => LoanProduct::where('company_id', Filament::getTenant()?->company_id)
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => self::applyProductDefaults($state, $set))
                        ->required(),
                    TextInput::make('amount')
                        ->label('Loan principal (GHS)')
                        ->numeric()
                        ->minValue(1)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => ($product = LoanProduct::find($get('loan_product_id')))
                            ? 'Allowed: '.Money::format($product->min_amount).' – '.Money::format($product->max_amount)
                            : null)
                        ->required(),
                    Select::make('savings_account_id')
                        ->label('Linked savings account')
                        ->options(fn (Get $get): array => Customer::find($get('customer_id'))?->savingsAccounts()
                            ->where('status', AccountStatus::Active)
                            ->get()
                            ->mapWithKeys(fn ($account): array => [$account->id => $account->account_number.' — '.Money::format($account->balance)])
                            ->all() ?? [])
                        ->helperText('Used for the eligibility check and write-off recovery.'),
                    TextInput::make('purpose')->label('Loan purpose')->maxLength(255)->columnSpanFull(),
                ]),
            Step::make('Settings')
                ->columns(3)
                ->schema([
                    TextInput::make('term_period_count')->label('Number of repayments')->integer()->minValue(1)->maxValue(520)->live(onBlur: true)->required(),
                    Select::make('repayment_frequency')->label('Repayment every')->options(LoanFrequency::class)->live()->required(),
                    TextInput::make('interest_rate_percent')->label('Interest rate (% per period)')->numeric()->minValue(0)->maxValue(100)->live(onBlur: true)->required(),
                    Select::make('interest_method')->label('Interest method')->options(InterestMethod::class)->live()->required(),
                    TextInput::make('grace_period_days')->label('Grace period (days)')->integer()->minValue(0)->maxValue(365)->default(0),
                    DatePicker::make('first_repayment_date')
                        ->label('First repayment date')
                        ->helperText('Optional — defaults to one period after disbursement.')
                        ->minDate(today())
                        ->live(),
                ]),
            Step::make('Charges')
                ->schema([
                    Repeater::make('charges')
                        ->hiddenLabel()
                        ->schema(self::chargeFields())
                        ->columns(2)
                        ->live()
                        ->addActionLabel('Add charge')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->helperText('Charges are deducted from the cash disbursed and booked as fee income.'),
                ]),
            Step::make('Collateral')
                ->schema([
                    Repeater::make('collaterals')
                        ->hiddenLabel()
                        ->schema(self::collateralFields())
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['description'] ?? null)
                        ->collapsible()
                        ->addActionLabel('Add collateral')
                        ->defaultItems(0)
                        ->reorderable(false),
                ]),
            Step::make('Guarantors')
                ->schema([
                    Repeater::make('guarantors')
                        ->hiddenLabel()
                        ->schema(self::guarantorFields(fn (Get $get): ?string => $get('../../customer_id')))
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->collapsible()
                        ->addActionLabel('Add guarantor')
                        ->defaultItems(0)
                        ->reorderable(false),
                ]),
            Step::make('Repayment schedule')
                ->schema([
                    TextEntry::make('schedule_preview')
                        ->hiddenLabel()
                        ->state(fn (Get $get): HtmlString => self::scheduleTable($get))
                        ->html(),
                ]),
            Step::make('Preview')
                ->columns(3)
                ->schema([
                    TextEntry::make('preview_client')->label('Client')->state(fn (Get $get): string => Customer::find($get('customer_id'))?->fullName() ?? '—'),
                    TextEntry::make('preview_product')->label('Product')->state(fn (Get $get): string => LoanProduct::find($get('loan_product_id'))?->name ?? '—'),
                    TextEntry::make('preview_principal')->label('Principal')->state(fn (Get $get): string => Money::format(Money::toMinorUnits((float) ($get('amount') ?: 0)))),
                    TextEntry::make('preview_terms')->label('Repayments')->state(fn (Get $get): string => ($get('term_period_count') ?: '—').' × '.(self::frequency($get)?->value ?? '—')),
                    TextEntry::make('preview_rate')->label('Interest')->state(fn (Get $get): string => ($get('interest_rate_percent') ?? '—').'% per period, '.(self::method($get)?->value ?? '—')),
                    TextEntry::make('preview_charges')->label('Charges')->state(fn (Get $get): string => Money::format(self::chargesTotal($get))),
                    TextEntry::make('preview_collateral')->label('Collateral items')->state(fn (Get $get): string => (string) count($get('collaterals') ?? [])),
                    TextEntry::make('preview_guarantors')->label('Guarantors')->state(fn (Get $get): string => collect($get('guarantors') ?? [])->pluck('name')->filter()->implode(', ') ?: '—'),
                    Textarea::make('notes')->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function chargeFields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(120),
            self::moneyInput('amount', 'Amount (GHS)')->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function collateralFields(): array
    {
        return [
            Select::make('type')
                ->options([
                    'Vehicle' => 'Vehicle',
                    'Land' => 'Land / property',
                    'Equipment' => 'Equipment / machinery',
                    'Household items' => 'Household items',
                    'Stock' => 'Business stock',
                    'Savings' => 'Savings / deposit',
                    'Other' => 'Other',
                ])
                ->required(),
            self::moneyInput('estimated_value', 'Estimated value (GHS)')->required(),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('serial_number')->label('Serial / registration / document no.')->maxLength(120),
            Textarea::make('notes')->columnSpanFull(),
        ];
    }

    /**
     * @param  Closure  $borrowerId  resolves the borrower's customer id (a guarantor can't be the borrower)
     * @return array<int, Component>
     */
    public static function guarantorFields(Closure $borrowerId): array
    {
        return [
            Select::make('customer_id')
                ->label('Existing client (optional)')
                ->options(fn (Get $get): array => Customer::where('branch_id', Filament::getTenant()?->id)
                    ->when($borrowerId($get), fn ($query, string $id) => $query->whereKeyNot($id))
                    ->orderBy('first_name')
                    ->get()
                    ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->fullName().' ('.$customer->customer_code.')'])
                    ->all())
                ->searchable()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($customer = Customer::find($state)) {
                        $set('name', $customer->fullName());
                        $set('phone', $customer->phone);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('name')->required()->maxLength(150),
            TextInput::make('phone')->tel()->maxLength(32),
            TextInput::make('relationship')->maxLength(60),
            self::moneyInput('guaranteed_amount', 'Amount guaranteed (GHS)'),
            Select::make('id_type')->label('ID type')->options(IdentificationType::class),
            TextInput::make('id_number')->label('ID number')->maxLength(60),
            Textarea::make('address')->columnSpanFull(),
        ];
    }

    /**
     * Turns the wizard's form state into ApplyForLoanAction input. Settings
     * only become overrides where they differ from the product.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toApplicationDetails(array $data, LoanProduct $product): array
    {
        // Enum Selects dehydrate to enum instances; compare and pass plain values.
        $data = array_map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value, $data);
        $rateBps = isset($data['interest_rate_percent']) ? (int) round((float) $data['interest_rate_percent'] * 100) : null;

        $differs = fn ($value, $productValue) => $value !== null && $value !== '' && $value != $productValue ? $value : null;

        return [
            'term_period_count' => $differs((int) ($data['term_period_count'] ?? 0) ?: null, $product->term_period_count),
            'repayment_frequency' => $differs($data['repayment_frequency'] ?? null, $product->repayment_frequency->value),
            'interest_rate_bps' => $differs($rateBps, $product->interest_rate_bps),
            'interest_method' => $differs($data['interest_method'] ?? null, $product->interest_method->value),
            'grace_period_days' => $differs(isset($data['grace_period_days']) ? (int) $data['grace_period_days'] : null, $product->grace_period_days),
            'first_repayment_date' => $data['first_repayment_date'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'charges' => array_values($data['charges'] ?? []),
            'collaterals' => array_values($data['collaterals'] ?? []),
            'guarantors' => array_values(array_map(fn (array $guarantor): array => array_filter($guarantor, fn ($value) => $value !== null && $value !== ''), $data['guarantors'] ?? [])),
        ];
    }

    private static function applyProductDefaults(?string $productId, Set $set): void
    {
        $product = LoanProduct::find($productId);
        if ($product === null) {
            return;
        }

        $set('term_period_count', $product->term_period_count);
        $set('repayment_frequency', $product->repayment_frequency->value);
        $set('interest_rate_percent', $product->interest_rate_bps / 100);
        $set('interest_method', $product->interest_method->value);
        $set('grace_period_days', $product->grace_period_days);
        $set('charges', $product->origination_fee_amount > 0
            ? [(string) str()->uuid() => ['name' => 'Processing fee', 'amount' => $product->origination_fee_amount / 100]]
            : []);
    }

    private static function moneyInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->formatStateUsing(fn ($state): ?float => $state === null || $state === '' ? null : ((int) $state) / 100)
            ->dehydrateStateUsing(fn ($state): ?int => $state === null || $state === '' ? null : Money::toMinorUnits((float) $state));
    }

    private static function frequency(Get $get): ?LoanFrequency
    {
        $value = $get('repayment_frequency');

        return $value instanceof LoanFrequency ? $value : LoanFrequency::tryFrom((string) $value);
    }

    private static function method(Get $get): ?InterestMethod
    {
        $value = $get('interest_method');

        return $value instanceof InterestMethod ? $value : InterestMethod::tryFrom((string) $value);
    }

    /** Repeater state is still in GHS while the form is open. */
    private static function chargesTotal(Get $get): int
    {
        return array_sum(array_map(fn (array $charge): int => Money::toMinorUnits((float) ($charge['amount'] ?? 0)), $get('charges') ?? []));
    }

    private static function scheduleTable(Get $get): HtmlString
    {
        $principal = Money::toMinorUnits((float) ($get('amount') ?: 0));
        $terms = (int) $get('term_period_count');
        $frequency = self::frequency($get);
        $method = self::method($get);

        if ($principal <= 0 || $terms <= 0 || $frequency === null || $method === null) {
            return new HtmlString('<p class="text-sm text-gray-500">Fill in the principal and settings to preview the schedule.</p>');
        }

        try {
            $result = app(LoanCalculator::class)->calculate(
                $principal,
                (int) round((float) $get('interest_rate_percent') * 100),
                $terms,
                $method,
                $frequency,
                today(),
                $get('first_repayment_date') ? Carbon::parse($get('first_repayment_date')) : null,
                self::chargesTotal($get),
            );
        } catch (Throwable) {
            return new HtmlString('<p class="text-sm text-danger-600">The schedule could not be calculated from these settings.</p>');
        }

        return self::renderSchedule($result, 'Estimated from disbursement today — final dates are set when the loan is disbursed.');
    }

    /**
     * @param  array<string, mixed>  $result  LoanCalculator::calculate() output
     */
    public static function renderSchedule(array $result, ?string $note = null): HtmlString
    {
        $rows = collect($result['installments'])->map(fn (array $row): string => '<tr class="border-b border-gray-100 dark:border-white/5">'
            .'<td class="px-2 py-1">'.$row['sequence'].'</td>'
            .'<td class="px-2 py-1">'.e(Carbon::parse($row['due_date'])->format('d M Y')).'</td>'
            .'<td class="px-2 py-1 text-right">'.e(Money::format($row['principal'])).'</td>'
            .'<td class="px-2 py-1 text-right">'.e(Money::format($row['interest'])).'</td>'
            .'<td class="px-2 py-1 text-right font-medium">'.e(Money::format($row['total'])).'</td>'
            .'<td class="px-2 py-1 text-right">'.e(Money::format($row['balance'])).'</td></tr>')->implode('');

        $summary = '<div class="mb-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">'
            .'<div><span class="text-gray-500">Total interest</span><br><strong>'.e(Money::format($result['total_interest'])).'</strong></div>'
            .'<div><span class="text-gray-500">Total repayable</span><br><strong>'.e(Money::format($result['total_repayable'])).'</strong></div>'
            .'<div><span class="text-gray-500">Charges</span><br><strong>'.e(Money::format($result['charges'])).'</strong></div>'
            .'<div><span class="text-gray-500">Cash to client</span><br><strong>'.e(Money::format($result['net_disbursed'])).'</strong></div>'
            .'</div>';

        return new HtmlString($summary
            .'<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-gray-200 text-left dark:border-white/10">'
            .'<th class="px-2 py-1">#</th><th class="px-2 py-1">Due date</th><th class="px-2 py-1 text-right">Principal</th>'
            .'<th class="px-2 py-1 text-right">Interest</th><th class="px-2 py-1 text-right">Total</th><th class="px-2 py-1 text-right">Balance</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .($note ? '<p class="mt-2 text-xs text-gray-500">'.e($note).'</p>' : ''));
    }
}
