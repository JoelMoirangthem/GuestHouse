<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Verify credentials, applying the 5-per-minute throttle from
     * SECURITY.md section 3.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
        ];

        if (! Auth::attempt([...$credentials, 'is_active' => true], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            app(\App\Application\Services\AuditLogger::class)->recordAuth(
                'LOGIN_FAILED',
                \App\Models\User::where('email', $credentials['email'])->first(),
                $credentials['email'],
            );

            // One generic message for every failure mode — wrong password,
            // unknown email, or deactivated account. Distinguishing them would
            // let an attacker enumerate valid accounts.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    /**
     * Keyed on email *and* IP so that one attacker cannot lock out a genuine
     * user by hammering their address from elsewhere.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
