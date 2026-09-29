<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\SettingsRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings — ROUTES.md `GET/PUT /admin/settings`.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'groups' => collect(SettingsRegistry::DEFINITIONS)->groupBy(fn ($d) => $d[2], preserveKeys: true),
            'values' => $this->settings->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate($this->settings->rules());
        $changed = $this->settings->save($data, $request->user());

        return redirect()->route('admin.settings.edit')->with('success', $changed === []
            ? 'Nothing changed.'
            : 'Settings saved: '.count($changed).' '.str('change')->plural(count($changed)).'.');
    }
}
