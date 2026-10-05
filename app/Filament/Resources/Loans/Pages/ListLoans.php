<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Enums\InstallmentStatus;
use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Schemas\LoanApplicationFields;
use App\Models\LoanProduct;
use App\Services\Loans\LoanCalculator;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->calculatorAction(),
            CreateAction::make()->label('New Loan Application'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tab = fn (string $label, ?Closure $scope = null, string $color = 'gray'): Tab => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $scope ? $scope($query) : $query)
            ->badge(fn (): int => ($scope ? $scope(LoanResource::getEloquentQuery()) : LoanResource::getEloquentQuery())->count())
            ->badgeColor($color)
            ->deferBadge();

        return [
            'all' => $tab('All'),
            'pending' => $tab('Pending approval', fn (Builder $query): Builder => $query->where('status', LoanStatus::Applied), 'warning'),
            'approved' => $tab('Awaiting disbursement', fn (Builder $query): Builder => $query->where('status', LoanStatus::Approved), 'info'),
            'active' => $tab('Active', fn (Builder $query): Builder => $query->where('status', LoanStatus::Disbursed), 'success'),
            'arrears' => $tab('In arrears', fn (Builder $query): Builder => $query->where('status', LoanStatus::Disbursed)
                ->whereHas('installments', fn (Builder $installments) => $installments
                    ->where('status', '!=', InstallmentStatus::Paid)
                    ->whereDate('due_date', '<', today())), 'danger'),
            'closed' => $tab('Closed', fn (Builder $query): Builder => $query->whereIn('status', [LoanStatus::Closed, LoanStatus::Refinanced])),
            'written_off' => $tab('Written off', fn (Builder $query): Builder => $query->where('status', LoanStatus::WrittenOff)),
        ];
    }

    /** eBanQR's "Loan terms for repayment calculation" — a what-if schedule, nothing saved. */
    private function calculatorAction(): Action
    {
        return Action::make('calculator')
            ->label('Loan calculator')
            ->icon(Heroicon::Calculator)
            ->color('gray')
            ->modalHeading('Loan terms for repayment calculation')
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([Grid::make(2)->schema([
                Select::make('loan_product_id')
                    ->label('Product (optional — fills the terms)')
                    ->options(fn (): array => LoanProduct::where('company_id', Filament::getTenant()?->company_id)->where('is_active', true)->pluck('name', 'id')->all())
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($product = LoanProduct::find($state)) {
                            $set('term_period_count', $product->term_period_count);
                            $set('repayment_frequency', $product->repayment_frequency->value);
                            $set('interest_rate_percent', $product->interest_rate_bps / 100);
                            $set('interest_method', $product->interest_method->value);
                            $set('charges', $product->origination_fee_amount / 100);
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('principal')->label('Loan principal (GHS)')->numeric()->minValue(1)->live(onBlur: true),
                TextInput::make('term_period_count')->label('Number of repayments')->integer()->minValue(1)->maxValue(520)->default(6)->live(onBlur: true),
                Select::make('repayment_frequency')->label('Repayment every')->options(LoanFrequency::class)->default('weekly')->live(),
                TextInput::make('interest_rate_percent')->label('Interest rate (% per period)')->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Select::make('interest_method')->label('Interest method')->options(InterestMethod::class)->default('flat')->live(),
                TextInput::make('charges')->label('Charges (GHS)')->numeric()->minValue(0)->default(0)->live(onBlur: true),
                DatePicker::make('disbursement_date')->label('Disbursement date')->default(today())->live(),
                DatePicker::make('first_repayment_date')->label('First repayment date (optional)')->live(),
                TextEntry::make('result')
                    ->hiddenLabel()
                    ->state(fn (Get $get): HtmlString => self::calculate($get))
                    ->html()
                    ->columnSpanFull(),
            ])]);
    }

    private static function calculate(Get $get): HtmlString
    {
        $principal = Money::toMinorUnits((float) ($get('principal') ?: 0));
        $frequency = $get('repayment_frequency');
        $frequency = $frequency instanceof LoanFrequency ? $frequency : LoanFrequency::tryFrom((string) $frequency);
        $method = $get('interest_method');
        $method = $method instanceof InterestMethod ? $method : InterestMethod::tryFrom((string) $method);

        if ($principal <= 0 || (int) $get('term_period_count') <= 0 || $frequency === null || $method === null) {
            return new HtmlString('<p class="text-sm text-gray-500">Enter a principal and the terms to see the repayment schedule.</p>');
        }

        try {
            $result = app(LoanCalculator::class)->calculate(
                $principal,
                (int) round((float) $get('interest_rate_percent') * 100),
                (int) $get('term_period_count'),
                $method,
                $frequency,
                Carbon::parse($get('disbursement_date') ?: today()),
                $get('first_repayment_date') ? Carbon::parse($get('first_repayment_date')) : null,
                Money::toMinorUnits((float) ($get('charges') ?: 0)),
            );
        } catch (Throwable) {
            return new HtmlString('<p class="text-sm text-danger-600">These terms can\'t be calculated.</p>');
        }

        return LoanApplicationFields::renderSchedule($result, 'Matures on '.Carbon::parse($result['maturity_date'])->format('d M Y').'.');
    }
}
