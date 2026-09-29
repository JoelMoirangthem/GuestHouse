<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Services\PasswordHistory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    private const GENERIC_MESSAGE = 'If that email address is registered, a password reset link has been sent to it.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always responds with the same generic message.
     *
     * Laravel's default returns a distinguishable error for unknown addresses,
     * which lets an attacker enumerate valid government email accounts. The
     * status is therefore discarded deliberately (SECURITY.md section 3).
     */
    public function email(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:150'],
        ]);

        // Throttle 3 per minute per email+IP.
        $key = 'pwd-reset|'.Str::lower((string) $request->string('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'email' => "Too many requests. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($key, 60);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', self::GENERIC_MESSAGE);
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->string('email'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                // SECURITY.md section 3: min 10, mixed case, digit, symbol.
                // uncompromised() is deliberately not used: it sends a hash
                // prefix to an external breach API, and this system is meant
                // for a closed government network.
                PasswordRule::min(10)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                // Checked here, AFTER the broker has validated the token, so the
                // reuse message can never reveal whether an address has an
                // account. Throwing keeps the token alive for another attempt.
                if (PasswordHistory::wasRecentlyUsed($user, $password)) {
                    throw ValidationException::withMessages(['password' => PasswordHistory::MESSAGE]);
                }
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Invalidate every other session so a stolen session cannot
                // outlive the password change.
                if (method_exists($user, 'getAuthIdentifier')) {
                    \DB::table('sessions')->where('user_id', $user->getAuthIdentifier())->delete();
                }
            }
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.');
        }

        return back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);
    }
}
