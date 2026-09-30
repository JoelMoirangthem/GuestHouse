<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // Render (and most PaaS) terminate TLS at a proxy and forward the request
        // over HTTP with X-Forwarded-* headers. Trust them so the framework knows
        // the original request was HTTPS — otherwise generated asset URLs come out
        // as http:// and the browser blocks them as mixed content on an https page.
        $middleware->trustProxies(at: '*', headers:
            Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        // SECURITY.md section 6 — applied to every web response.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An expired CSRF token ("Your session has expired", 419). Rather than a
        // dead-end error page, send the user back to the form they submitted
        // with what they typed (never passwords or files) and one clear line.
        // If they were on a signed-in page and the session idled out, go to
        // sign-in instead, and return them to that page afterwards.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419
                || ! $e->getPrevious() instanceof TokenMismatchException
                || $request->expectsJson()
                || ! $request->hasSession()) {
                return null;
            }

            $referer = (string) $request->headers->get('referer');
            $sameOrigin = $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost().'/');

            $previous = null;
            if ($sameOrigin) {
                try {
                    $previous = app('router')->getRoutes()->match(Request::create($referer, 'GET'));
                } catch (Throwable) {
                    $previous = null;
                }
            }

            $previousNeedsAuth = $previous !== null
                && in_array('auth', $previous->gatherMiddleware(), true);

            if ($previousNeedsAuth && ! Auth::check()) {
                $request->session()->put('url.intended', $referer);

                return redirect()->route('login')->withErrors([
                    'session' => 'You were signed out after '.config('session.lifetime')
                        .' minutes of inactivity. Please sign in again to continue.',
                ]);
            }

            $target = $previous !== null ? $referer : route('login');

            return redirect()->to($target)
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->withErrors([
                    'session' => 'This page had been open for a while, so it was refreshed. Please submit again.',
                ]);
        });
    })->create();
