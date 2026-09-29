<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\RoomAdminService;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Room types — ROUTES.md `/admin/room-types`. Types are a table, not an enum
 * (PLAN.md decision 4), so a new type needs no deploy. A type is retired by
 * deactivating it; its rooms then drop out of availability searches.
 */
class RoomTypeController extends Controller
{
    public function __construct(
        private readonly RoomAdminService $rooms,
    ) {}

    public function index(): View
    {
        return view('admin.room-types.index', [
            'types' => RoomType::query()->withCount('rooms')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.room-types.form', ['type' => new RoomType(['is_active' => true, 'default_capacity' => 2, 'has_ac' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function edit(RoomType $roomType): View
    {
        return view('admin.room-types.form', ['type' => $roomType]);
    }

    public function update(Request $request, RoomType $roomType): RedirectResponse
    {
        return $this->save($request, $roomType);
    }

    private function save(Request $request, ?RoomType $type): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('room_types', 'code')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(['VIP', 'NORMAL'])],
            'default_capacity' => ['required', 'integer', 'between:1,10'],
            'has_ac' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'The code may contain only letters, digits and underscores, e.g. DELUXE_AC.',
        ]);

        try {
            $saved = $this->rooms->saveRoomType($type, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('admin.room-types.index')
            ->with('success', $type === null ? "Room type {$saved->name} added." : "{$saved->name} updated.");
    }
}
