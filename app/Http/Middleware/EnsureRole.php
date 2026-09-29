<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of the three authorization layers in SECURITY.md section 4.
 *
 * Usage: ->middleware('role:admin') or ->middleware('role:manager,adg')
 *
 * This middleware is coarse on purpose: it answers only "may this role enter
 * this area of the application". Per-record questions ("is this the requester's
 * own booking", "is the request in a status that permits this action") belong to
 * policies, which are layer 3.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, 'Authentication required.');
        }

        // A deactivated account must not retain access through a live session.
        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'This account has been deactivated.');
        }

        $slug = $user->role?->slug;

        if ($slug === null || ! in_array($slug, $roles, true)) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
