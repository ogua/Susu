<?php

namespace App\Filament\Resources\Staff;

use App\Actions\Staff\IssueTemporaryPasswordAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Staff actions shared by the branch Staff table and the super admin's
 * Users table / company Staff tab.
 */
class StaffActions
{
    /** Super admin only: clears a user's authenticator app so they can set it up again (lost phone). */
    public static function resetTwoFactor(): Action
    {
        return Action::make('resetTwoFactor')
            ->label('Reset two-factor')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => "{$record->name} will sign in with their password only and can set up an authenticator app again from their profile.")
            ->visible(fn (User $record): bool => (filled($record->app_authentication_secret) || $record->hasEmailAuthentication())
                && (Filament::auth()->user()?->hasRole('super_admin') ?? false))
            ->action(function (User $record): void {
                $record->saveAppAuthenticationSecret(null);
                $record->saveAppAuthenticationRecoveryCodes(null);
                $record->toggleEmailAuthentication(false);

                activity('security')
                    ->causedBy(Filament::auth()->user())
                    ->performedOn($record)
                    ->event('two_factor_reset')
                    ->log("Two-factor authentication reset for {$record->name}");

                Notification::make()->title("Two-factor reset for {$record->name}")->success()->send();
            });
    }

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
