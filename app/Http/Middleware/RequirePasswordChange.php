<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel middleware: a user still on a temporary password (set by an admin
 * or sent at onboarding) is sent to their profile page until they choose
 * their own. Only full-page GETs are redirected, so the profile page's own
 * Livewire requests and logout keep working.
 */
class RequirePasswordChange
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (
            $user instanceof User
            && $user->must_change_password
            && $request->isMethod('GET')
            && ! $request->hasHeader('X-Livewire')
            && ! $request->routeIs('filament.*.auth.profile')
        ) {
            Notification::make()
                ->title('Choose a new password')
                ->body('You signed in with a temporary password. Set your own password to continue.')
                ->warning()
                ->persistent()
                ->send();

            return redirect(Filament::getCurrentPanel()->getProfileUrl());
        }

        return $next($request);
    }
}
