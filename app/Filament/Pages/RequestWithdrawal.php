<?php

namespace App\Filament\Pages;

use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\AccountStatus;
use App\Models\SavingsAccount;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Field agent raises a withdrawal request on behalf of a customer whose
 * account they manage. Approval/payout stays with branch managers via
 * WithdrawalRequestResource — this page only ever creates Pending requests.
 */
class RequestWithdrawal extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.request-withdrawal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Request Withdrawal';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('field_agent') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestWithdrawal')
                ->label('Request Withdrawal')
                ->schema([
                    Select::make('savings_account_id')
                        ->label('Savings Account')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->accountsQuery()
                            ->where(function (Builder $query) use ($search): void {
                                $query->where('account_number', 'like', "%{$search}%")
                                    ->orWhereHas('customer', function (Builder $query) use ($search): void {
                                        $query->where('first_name', 'like', "%{$search}%")
                                            ->orWhere('last_name', 'like', "%{$search}%");
                                    });
                            })
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (SavingsAccount $account): array => [$account->id => $this->accountLabel($account)])
                            ->all())
                        ->getOptionLabelUsing(function ($value): ?string {
                            $account = $this->accountsQuery()->find($value);

                            return $account ? $this->accountLabel($account) : null;
                        })
                        ->required(),
                    TextInput::make('amount')
                        ->label('Amount (GHS)')
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),
                    Textarea::make('reason')
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $account = $this->accountsQuery()->find($data['savings_account_id']);

                    if ($account === null) {
                        throw ValidationException::withMessages([
                            'savings_account_id' => 'You are not the assigned agent for this account.',
                        ]);
                    }

                    app(RequestWithdrawalAction::class)->execute(
                        Filament::auth()->user(),
                        $account,
                        Money::toMinorUnits($data['amount']),
                        $data['reason'] ?? null,
                    );

                    Notification::make()->title('Withdrawal request submitted')->success()->send();

                    $this->resetTable();
                }),
        ];
    }

    /**
     * @return Builder<SavingsAccount>
     */
    private function accountsQuery(): Builder
    {
        return SavingsAccount::query()
            ->where('agent_id', Filament::auth()->user()->id)
            ->where('status', AccountStatus::Active);
    }

    private function accountLabel(SavingsAccount $account): string
    {
        return $account->customer->fullName().' — '.$account->account_number;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WithdrawalRequest::query()->where('requested_by', Filament::auth()->user()->id))
            ->columns([
                TextColumn::make('savingsAccount.account_number')->label('Account'),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record): string => $record->customer->fullName()),
                TextColumn::make('amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('reason')->limit(40),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No withdrawal requests yet.');
    }
}
