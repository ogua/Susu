<?php

namespace App\Filament\Resources\GroupLoans\Schemas;

use App\Enums\LoanFrequency;
use App\Models\Customer;
use App\Services\Loans\PeriodicScheduleGenerator;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Issues one loan to one member — deposit, activation and repayments happen via table actions afterward. */
class GroupLoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('loan_group_id')
                    ->label('Loan group')
                    ->relationship('loanGroup', 'name', fn ($query) => $query->where('branch_id', Filament::getTenant()?->id)->where('is_active', true))
                    ->searchable()
                    ->required(),
                Select::make('customer_id')
                    ->label('Member')
                    ->options(fn (): array => Customer::where('branch_id', Filament::getTenant()?->id)
                        ->get()
                        ->mapWithKeys(fn ($customer) => [$customer->id => $customer->fullName().' ('.$customer->customer_code.')'])
                        ->all())
                    ->searchable()
                    ->required(),
                TextInput::make('principal_amount')
                    ->label('Loan amount (GHS)')
                    ->numeric()
                    ->required()
                    ->live(onBlur: true),
                TextInput::make('security_deposit_amount')
                    ->label('Security deposit (GHS)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('periodic_amount')
                    ->label('Amount to be paid each period (GHS)')
                    ->numeric()
                    ->required()
                    ->live(onBlur: true),
                Select::make('repayment_frequency')
                    ->label('Frequency')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                    ])
                    ->default('weekly')
                    ->required()
                    ->live(),
                DatePicker::make('start_date')
                    ->label('First payment date')
                    ->default(now())
                    ->minDate(now()->startOfDay())
                    ->required()
                    ->live(onBlur: true),
                Placeholder::make('schedule_preview')
                    ->label('Payment schedule')
                    ->content(fn (Get $get): string => self::previewSchedule($get)),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    private static function previewSchedule(Get $get): string
    {
        $principal = Money::toMinorUnits($get('principal_amount') ?: 0);
        $periodic = Money::toMinorUnits($get('periodic_amount') ?: 0);
        $frequency = LoanFrequency::tryFrom((string) $get('repayment_frequency')) ?? LoanFrequency::Weekly;
        $startDate = $get('start_date') ? Carbon::parse($get('start_date')) : now();

        if ($principal <= 0 || $periodic <= 0) {
            return 'Enter a loan amount and a periodic amount to preview the schedule.';
        }

        try {
            $schedule = app(PeriodicScheduleGenerator::class)->generate($principal, $periodic, $frequency, $startDate->copy());
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? 'The schedule could not be generated.';
        }

        $count = count($schedule);
        $last = $schedule[$count - 1];
        $frequencyWord = $frequency->value;

        return "{$count} {$frequencyWord} payments of ".Money::format($periodic)
            .' from '.$schedule[0]->dueDate->toFormattedDateString()
            .'; final payment '.Money::format($last->principalDue).' on '.$last->dueDate->toFormattedDateString().'.';
    }
}
