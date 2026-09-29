<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY.md section 6 — security response headers.
 *
 * HSTS is only sent over HTTPS: sending it over plain HTTP is ignored by
 * browsers and, worse, sending it in local development would pin localhost to
 * HTTPS in the developer's browser and lock them out of the dev server.
 *
 * CONTENT SECURITY POLICY. Scripts load from this origin only. Two relaxations
 * are required by the chosen frontend and are the reason they are listed here:
 *
 *   - script-src 'unsafe-eval': Alpine.js evaluates its inline x-data / @click
 *     expressions with new Function(). Removing it means moving to Alpine's CSP
 *     build and rewriting every inline expression as a registered component.
 *   - style-src 'unsafe-inline': the occupancy chart and a few progress bars
 *     set their height/width with an inline style attribute.
 *
 * No inline <script> is allowed, which is what actually stops injected markup
 * from running. Google Fonts is the one external origin (see the layouts).
 * The policy is skipped while the Vite dev server is running, since it serves
 * scripts from another port.
 */
class SecurityHeaders
{
    private const CSP = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Deny access to device APIs this application never uses.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        if (! Vite::isRunningHot() && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', self::CSP));
        }

        // Do not advertise the PHP version. php.ini `expose_php = Off` is the
        // real fix on the server; this covers SAPIs where the header is added
        // by PHP itself before the response is sent.
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
