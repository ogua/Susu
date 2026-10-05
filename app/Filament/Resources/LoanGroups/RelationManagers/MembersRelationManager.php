<?php

namespace App\Filament\Resources\LoanGroups\RelationManagers;

use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanFrequency;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Services\Loans\PeriodicScheduleGenerator;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The roster. Members are added here and taken through issue → security deposit →
 * activation; repayments and write-offs live in the "Member Loans" tab.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('customer.first_name')
                    ->label('Members name')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('security_deposit')
                    ->label('Security deposit')
                    ->state(fn (LoanGroupMember $record): string => $record->openLoan
                        ? Money::format($record->openLoan->security_deposit_amount)
                        : '—'),
                TextColumn::make('amount_to_be_paid')
                    ->label('Amount to be paid')
                    ->state(fn (LoanGroupMember $record): string => $record->openLoan
                        ? Money::format($record->openLoan->periodic_amount)
                        : '—'),
                TextColumn::make('loan_outstanding')
                    ->label('Loan outstanding')
                    ->state(fn (LoanGroupMember $record): string => $record->openLoan
                        ? Money::format($record->openLoan->outstanding_balance)
                        : '—'),
                TextColumn::make('loan_stage')
                    ->label('Loan')
                    ->badge()
                    ->state(fn (LoanGroupMember $record): string => self::loanStage($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Awaiting deposit' => 'warning',
                        'Ready to activate' => 'info',
                        'Active' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('status')->badge(),
                TextColumn::make('joined_at')->dateTime(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add Member')
                    ->authorize(fn (): bool => Filament::auth()->user()->can('update', $this->getOwnerRecord()))
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->options(fn (): array => Customer::where('branch_id', Filament::getTenant()?->id)
                                ->get()
                                ->mapWithKeys(fn ($customer) => [$customer->id => $customer->fullName().' ('.$customer->customer_code.')'])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var LoanGroup $loanGroup */
                        $loanGroup = $this->getOwnerRecord();

                        app(AddLoanGroupMemberAction::class)->execute(
                            $loanGroup,
                            Customer::findOrFail($data['customer_id']),
                        );

                        Notification::make()->title('Member added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('issueLoan')
                    ->label('Issue Loan')
                    ->color('primary')
                    ->visible(fn (LoanGroupMember $record): bool => $record->status === 'active' && $record->openLoan === null)
                    ->authorize(fn (): bool => Filament::auth()->user()->can('update', $this->getOwnerRecord()))
                    ->schema([
                        TextInput::make('principal_amount')->label('Loan amount (GHS)')->numeric()->required()->live(onBlur: true),
                        TextInput::make('security_deposit_amount')->label('Security deposit (GHS)')->numeric()->default(0)->required(),
                        TextInput::make('periodic_amount')->label('Amount to be paid each period (GHS)')->numeric()->required()->live(onBlur: true),
                        Select::make('repayment_frequency')
                            ->label('Frequency')
                            ->options(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'])
                            ->default('weekly')->required()->live(),
                        DatePicker::make('start_date')->label('First payment date')->default(now())->minDate(now()->startOfDay())->required()->live(onBlur: true),
                        Placeholder::make('schedule_preview')
                            ->label('Payment schedule')
                            ->content(fn (Get $get): string => self::previewSchedule($get)),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->action(function (array $data, LoanGroupMember $record): void {
                        try {
                            app(IssueGroupMemberLoanAction::class)->execute(
                                issuedBy: Filament::auth()->user(),
                                loanGroup: $this->getOwnerRecord(),
                                customer: $record->customer,
                                principal: Money::toMinorUnits($data['principal_amount']),
                                securityDeposit: Money::toMinorUnits($data['security_deposit_amount'] ?? 0),
                                periodicAmount: Money::toMinorUnits($data['periodic_amount']),
                                frequency: LoanFrequency::from($data['repayment_frequency']),
                                startDate: Carbon::parse($data['start_date']),
                                notes: $data['notes'] ?: null,
                            );
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Loan issued')
                            ->body(Money::toMinorUnits($data['security_deposit_amount'] ?? 0) > 0
                                ? 'Next: record the security deposit, then activate the loan.'
                                : 'Next: activate the loan to disburse it.')
                            ->success()
                            ->send();
                    }),
                MemberLoansRelationManager::recordDepositAction(fn (LoanGroupMember $record): ?GroupLoan => $record->openLoan),
                MemberLoansRelationManager::activateAction(fn (LoanGroupMember $record): ?GroupLoan => $record->openLoan),
                Action::make('removeMember')
                    ->color('danger')
                    ->visible(fn (LoanGroupMember $record): bool => $record->status === 'active' && $record->activeLoan === null)
                    ->authorize(fn (): bool => Filament::auth()->user()->can('update', $this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (LoanGroupMember $record): void {
                        try {
                            app(RemoveLoanGroupMemberAction::class)->execute($record);
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Member removed')->success()->send();
                    }),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'openLoan.customer']))
            ->defaultSort('joined_at');
    }

    /** Where the member's open loan sits in the issue → deposit → activate flow. */
    private static function loanStage(LoanGroupMember $member): string
    {
        $loan = $member->openLoan;

        return match (true) {
            $loan === null => 'No loan',
            $loan->status === GroupLoanStatus::Active => 'Active',
            $loan->deposit_status === DepositStatus::Pending => 'Awaiting deposit',
            default => 'Ready to activate',
        };
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

        return "{$count} {$frequency->value} payments of ".Money::format($periodic)
            .' from '.$schedule[0]->dueDate->toFormattedDateString()
            .'; final payment '.Money::format($last->principalDue).' on '.$last->dueDate->toFormattedDateString().'.';
    }
}
