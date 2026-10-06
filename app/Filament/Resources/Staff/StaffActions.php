<?php

namespace App\Filament\Resources\Staff;

use App\Actions\Staff\IssueTemporaryPasswordAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Staff actions shared by the branch Staff table and the super admin's
 * Users table / company Staff tab.
 */
class StaffActions
{
    public static function resetPassword(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset password')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => "A new temporary password will be emailed/texted to {$record->name}. They will be signed out of the apps and must choose a new password at their next sign-in.")
            ->visible(fn (User $record): bool => Gate::allows('update', $record) && ! $record->hasRole('super_admin'))
            ->action(function (User $record): void {
                app(IssueTemporaryPasswordAction::class)->execute($record);

                Notification::make()
                    ->title("New sign-in details sent to {$record->name}")
                    ->success()
                    ->send();
            });
    }
}
