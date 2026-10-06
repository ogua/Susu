<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\TwoFactorCode;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * Two-factor step for API sign-in (mobile and desktop apps), matching the
 * web panels: a user with an authenticator app enters its code (or a
 * recovery code); everyone else who needs a second factor gets a one-time
 * code by email and SMS. Company admins always need one — they can manage
 * staff and money from the apps.
 *
 * Login answers with a short-lived challenge token instead of an API token;
 * the app exchanges it plus the code for the real token. The challenge lives
 * in the cache (hashed code, user, attempts) for CHALLENGE_MINUTES.
 */
class ApiTwoFactorChallenge
{
    public const CHALLENGE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function requiredFor(User $user): bool
    {
        return filled($user->app_authentication_secret)
            || $user->hasEmailAuthentication()
            || $user->hasRole('company_admin');
    }

    /**
     * @return array{challenge_token: string, methods: list<string>, code_sent_to: array{email: ?string, phone: ?string}}
     */
    public function start(User $user): array
    {
        $token = Str::random(48);
        $usesApp = filled($user->app_authentication_secret);

        $this->put($token, ['user_id' => $user->id, 'code_hash' => null, 'attempts' => 0]);

        $sentTo = ['email' => null, 'phone' => null];
        if (! $usesApp) {
            $sentTo = $this->sendCode($token, $user);
        }

        return [
            'challenge_token' => $token,
            'methods' => $usesApp ? ['app', 'recovery_code', 'code'] : ['code'],
            'code_sent_to' => $sentTo,
        ];
    }

    /**
     * Emails/texts a fresh code for the challenge (also the "send me a code
     * instead" path for authenticator-app users).
     *
     * @return array{email: ?string, phone: ?string} masked destinations
     */
    public function resend(string $challengeToken): array
    {
        $challenge = $this->challenge($challengeToken);
        $user = User::findOrFail($challenge['user_id']);

        $limiterKey = 'api-2fa-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            throw ValidationException::withMessages(['code' => 'Too many codes requested. Wait a minute and try again.']);
        }
        RateLimiter::hit($limiterKey, 60);

        return $this->sendCode($challengeToken, $user);
    }

    /** Checks the code and, on success, consumes the challenge and returns its user. */
    public function verify(string $challengeToken, #[SensitiveParameter] string $code): User
    {
        $challenge = $this->challenge($challengeToken);

        if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->key($challengeToken));
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Sign in again.']);
        }

        $user = User::findOrFail($challenge['user_id']);
        $code = trim($code);

        if (! $this->matches($user, $challenge, $code)) {
            $challenge['attempts']++;
            $this->put($challengeToken, $challenge);

            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }

        Cache::forget($this->key($challengeToken));

        return $user;
    }

    /**
     * @param  array{user_id: string, code_hash: ?string, attempts: int}  $challenge
     */
    private function matches(User $user, array $challenge, string $code): bool
    {
        if ($challenge['code_hash'] !== null && Hash::check($code, $challenge['code_hash'])) {
            return true;
        }

        if (filled($user->app_authentication_secret)) {
            $app = AppAuthentication::make()->recoverable();

            try {
                if (preg_match('/^\d{6}$/', $code) && $app->verifyCode($code, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true)) {
                    return true;
                }

                return $app->verifyRecoveryCode($code, $user);
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array{email: ?string, phone: ?string}
     */
    private function sendCode(string $challengeToken, User $user): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = $this->challenge($challengeToken);
        $challenge['code_hash'] = Hash::make($code);
        $this->put($challengeToken, $challenge);

        $user->notify(new TwoFactorCode($code, self::CHALLENGE_MINUTES));

        return [
            'email' => filled($user->email) ? $this->mask($user->email, '@') : null,
            'phone' => filled($user->phone) ? '•••'.substr((string) $user->phone, -3) : null,
        ];
    }

    /**
     * @return array{user_id: string, code_hash: ?string, attempts: int}
     */
    private function challenge(string $challengeToken): array
    {
        $challenge = Cache::get($this->key($challengeToken));

        if (! is_array($challenge)) {
            throw ValidationException::withMessages(['code' => 'This sign-in has expired. Sign in again.']);
        }

        return $challenge;
    }

    /**
     * @param  array{user_id: string, code_hash: ?string, attempts: int}  $challenge
     */
    private function put(string $challengeToken, array $challenge): void
    {
        Cache::put($this->key($challengeToken), $challenge, now()->addMinutes(self::CHALLENGE_MINUTES));
    }

    private function key(string $challengeToken): string
    {
        return 'api-2fa:'.hash('sha256', $challengeToken);
    }

    private function mask(string $email, string $at): string
    {
        [$name, $domain] = array_pad(explode($at, $email, 2), 2, '');

        return mb_substr($name, 0, 2).'•••'.$at.$domain;
    }
}
