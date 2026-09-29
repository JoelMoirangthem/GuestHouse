<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Mail\GoogleTokenStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Connects a Gmail account so the system can send mail through the Gmail API.
 *
 * Administrator only. The consent step genuinely requires a human at a browser —
 * Google will not issue a refresh token to an unattended process — so this is a
 * one-time setup screen rather than something the application can do for itself.
 */
class GoogleMailController extends Controller
{
    public function __construct(
        private readonly GoogleTokenStore $tokens,
    ) {}

    public function show(Request $request): View
    {
        return view('admin.mail.google', [
            'connected' => $this->tokens->hasRefreshToken(),
            'sender' => $this->tokens->senderAddress(),
            'clientId' => (string) config('services.google.client_id'),
            'redirectUri' => (string) config('services.google.redirect_uri'),
            'mailer' => (string) config('mail.default'),
        ]);
    }

    /**
     * Start the consent flow.
     */
    public function redirect(Request $request): RedirectResponse
    {
        // CSRF protection for the OAuth round trip: the state is checked on return
        // so a forged callback cannot plant somebody else's token.
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes')),
            // offline + consent together are what actually produce a refresh
            // token. Without prompt=consent Google may return only an access
            // token on repeat authorisations, which then expires in an hour.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return redirect()->away(config('services.google.auth_uri').'?'.$query);
    }

    /**
     * Exchange the authorisation code for tokens.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('admin.mail.google')
                ->withErrors(['google' => 'Google returned: '.$request->string('error')]);
        }

        $expected = $request->session()->pull('google_oauth_state');

        if ($expected === null || ! hash_equals($expected, (string) $request->string('state'))) {
            return redirect()->route('admin.mail.google')
                ->withErrors(['google' => 'The authorisation state did not match. Please start again.']);
        }

        $response = Http::asForm()->timeout(20)->post(config('services.google.token_uri'), [
            'code' => (string) $request->string('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            return redirect()->route('admin.mail.google')->withErrors([
                'google' => 'Token exchange failed: '
                    .($response->json('error_description') ?? $response->json('error') ?? 'unknown error'),
            ]);
        }

        $refresh = $response->json('refresh_token');

        if (! is_string($refresh) || $refresh === '') {
            // Happens when the account has authorised before and Google decides a
            // new refresh token is unnecessary. Revoking access forces a fresh one.
            return redirect()->route('admin.mail.google')->withErrors([
                'google' => 'Google did not return a refresh token. Remove this app at '
                    .'myaccount.google.com/permissions and authorise again.',
            ]);
        }

        // Identify which mailbox was authorised, so the screen can show it.
        $email = 'unknown';

        if ($accessToken = $response->json('access_token')) {
            $info = Http::withToken($accessToken)->timeout(15)
                ->get(config('services.google.userinfo_uri'));

            if ($info->successful()) {
                $email = (string) ($info->json('email') ?? 'unknown');
            }
        }

        $this->tokens->store($refresh, $email);

        return redirect()->route('admin.mail.google')
            ->with('success', "Gmail connected as {$email}. Send a test message to confirm.");
    }

    /**
     * Prove it works end to end, which is the only claim worth making.
     */
    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email'],
        ]);

        try {
            Mail::raw(
                'This is a test message from the Guest House Booking System at '
                .config('gh.building').', '.config('gh.institution').".\n\n"
                ."If you received this, outbound mail is working.\n\n"
                .'Sent at '.now()->format('d/m/Y, g:i a').'.',
                fn ($m) => $m->to($validated['to'])
                    ->subject('Guest House Booking System — test message')
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['google' => 'Send failed: '.$e->getMessage()]);
        }

        return back()->with('success', "Test message sent to {$validated['to']}.");
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $refresh = $this->tokens->refreshToken();
        $revokedAtGoogle = true;

        if ($refresh !== null) {
            // Tell Google too, so the grant is actually withdrawn rather than just
            // forgotten locally. A network failure or rejection here must not stop
            // the local disconnect — the administrator asked for sending to stop.
            try {
                $revokedAtGoogle = Http::asForm()->timeout(10)
                    ->post(config('services.google.revoke_uri'), ['token' => $refresh])
                    ->successful();
            } catch (\Throwable $e) {
                report($e);
                $revokedAtGoogle = false;
            }
        }

        $this->tokens->revoke();

        return back()->with('success', $revokedAtGoogle
            ? 'Gmail disconnected.'
            : 'Gmail disconnected locally, but Google could not confirm the revocation. '
                .'Remove this app at myaccount.google.com/permissions to withdraw access fully.');
    }
}
