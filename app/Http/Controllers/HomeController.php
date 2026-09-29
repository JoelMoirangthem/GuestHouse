<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Role-based landing.
 */
class HomeController extends Controller
{
    /**
     * Each role has a disjoint work queue, so a single shared dashboard would
     * cost three of the four roles an extra click on every sign-in.
     */
    public function index(Request $request): RedirectResponse
    {
        $slug = $request->user()->roleSlug();

        return redirect()->route($slug?->homeRoute() ?? 'my.requests.index');
    }
}
