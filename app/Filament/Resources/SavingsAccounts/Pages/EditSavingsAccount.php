<?php

namespace App\Filament\Resources\SavingsAccounts\Pages;

use App\Actions\Savings\CloseSavingsAccountAction;
use App\Enums\AccountStatus;
use App\Filament\Resources\SavingsAccounts\SavingsAccountResource;
use App\Models\SavingsAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSavingsAccount extends EditRecord
{
    protected static string $resource = SavingsAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close account')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Only an empty account with no withdrawal in progress and no open loan can be closed. Its history is kept.')
                ->visible(fn (SavingsAccount $record): bool => $record->status !== AccountStatus::Closed)
                ->action(function (SavingsAccount $record): void {
                    try {
                        app(CloseSavingsAccountAction::class)->execute($record, Filament::auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Account closed')->success()->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
