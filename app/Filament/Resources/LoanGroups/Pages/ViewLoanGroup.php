<?php

namespace App\Filament\Resources\LoanGroups\Pages;

use App\Actions\LoanGroups\IssueLoansToGroupAction;
use App\Actions\LoanGroups\OpenSavingsForGroupAction;
use App\Enums\LoanFrequency;
use App\Enums\SavingsProductType;
use App\Filament\Pages\CollectionSheet;
use App\Filament\Resources\LoanGroups\LoanGroupResource;
use App\Models\LoanGroup;
use App\Models\SavingsProduct;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ViewLoanGroup extends ViewRecord
{
    protected static string $resource = LoanGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enterTransaction')
                ->label('Enter Transaction')
                ->icon(Heroicon::Banknotes)
                ->url(fn (LoanGroup $record): string => CollectionSheet::getUrl(['group' => $record->id])),
            Action::make('issueGroupLoan')
                ->label('Issue loan to group')
                ->icon(Heroicon::Scale)
                ->color('gray')
                ->authorize('update')
                ->modalDescription('Issues the same terms to every active member who has no open loan. Each member\'s loan then goes through deposit → activation as usual.')
                ->schema([
                    TextInput::make('principal_amount')->label('Loan amount per member (GHS)')->numeric()->minValue(1)->required(),
                    TextInput::make('security_deposit_amount')->label('Security deposit (GHS)')->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('periodic_amount')->label('Amount to be paid each period (GHS)')->numeric()->minValue(1)->required(),
                    Select::make('repayment_frequency')
                        ->label('Frequency')
                        ->options(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'])
                        ->default('weekly')
                        ->required(),
                    DatePicker::make('start_date')->label('First payment date')->default(now())->minDate(now()->startOfDay())->required(),
                    Textarea::make('notes'),
                ])
                ->action(function (array $data, LoanGroup $record): void {
                    try {
                        $result = app(IssueLoansToGroupAction::class)->execute(
                            issuedBy: Filament::auth()->user(),
                            loanGroup: $record,
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

                    self::notifyResult(count($result['issued']).' loan(s) issued', $result['skipped']);
                }),
            Action::make('openGroupSavings')
                ->label('Open savings for group')
                ->icon(Heroicon::BuildingLibrary)
                ->color('gray')
                ->authorize('update')
                ->modalDescription('Opens an account on this product for every active member who doesn\'t already have one.')
                ->schema([
                    Select::make('savings_product_id')
                        ->label('Savings product')
                        ->options(fn (LoanGroup $record): array => SavingsProduct::where('company_id', $record->company_id)
                            ->where('is_active', true)
                            ->where('type', SavingsProductType::DailySusu)
                            ->pluck('name', 'id')
                            ->all())
                        ->required(),
                    TextInput::make('contribution_amount')
                        ->label('Daily contribution (GHS)')
                        ->helperText('Leave empty to use the product default.')
                        ->numeric()
                        ->minValue(0.01),
                ])
                ->action(function (array $data, LoanGroup $record): void {
                    try {
                        $result = app(OpenSavingsForGroupAction::class)->execute(
                            $record,
                            SavingsProduct::findOrFail($data['savings_product_id']),
                            contributionAmount: filled($data['contribution_amount'] ?? null) ? Money::toMinorUnits($data['contribution_amount']) : null,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    self::notifyResult(count($result['opened']).' account(s) opened', $result['skipped']);
                }),
            EditAction::make(),
        ];
    }

    /**
     * @param  array<string, string>  $skipped
     */
    private static function notifyResult(string $title, array $skipped): void
    {
        $notification = Notification::make()->title($title);

        if ($skipped === []) {
            $notification->success()->send();

            return;
        }

        $notification->warning()
            ->body(count($skipped).' skipped: '.implode(' · ', array_slice(array_values($skipped), 0, 5)).(count($skipped) > 5 ? ' …' : ''))
            ->persistent()
            ->send();
    }
}
