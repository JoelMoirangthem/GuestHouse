<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\RoomAdminService;
use App\Domain\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Physical rooms plus block / unblock — ROUTES.md `/admin/rooms`.
 *
 * No destroy action: allotments reference rooms, and a room that is gone for
 * good is represented by a permanent BLOCKED status, which keeps history intact
 * and keeps the inventory total honest.
 */
class RoomController extends Controller
{
    public function __construct(
        private readonly RoomAdminService $rooms,
    ) {}

    public function index(Request $request): View
    {
        $typeFilter = (int) $request->query('type', 0);
        $stateFilter = (string) $request->query('state', '');

        return view('admin.rooms.index', [
            'rooms' => Room::query()
                ->with('roomType')
                ->when($typeFilter > 0, fn ($q) => $q->where('room_type_id', $typeFilter))
                ->when($stateFilter === 'blocked', fn ($q) => $q->where(fn ($w) => $w
                    ->where('status', '!=', RoomStatus::ACTIVE->value)
                    ->orWhereNotNull('blocked_from')))
                ->when($stateFilter === 'active', fn ($q) => $q
                    ->where('status', RoomStatus::ACTIVE->value)
                    ->whereNull('blocked_from'))
                // Natural order ("9" before "10") without a MySQL-only CAST, so it
                // also runs on PostgreSQL (Render): shorter numbers sort first.
                ->orderByRaw('LENGTH(room_number), room_number')
                ->paginate(40)
                ->withQueryString(),
            'types' => RoomType::orderBy('sort_order')->get(),
            'typeFilter' => $typeFilter,
            'stateFilter' => $stateFilter,
        ]);
    }

    public function create(): View
    {
        return view('admin.rooms.form', [
            'room' => new Room,
            'types' => RoomType::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(null));

        try {
            $room = $this->rooms->createRoom($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['room' => $e->getMessage()]);
        }

        return redirect()->route('admin.rooms.index')->with('success', "Room {$room->room_number} added.");
    }

    public function edit(Room $room): View
    {
        return view('admin.rooms.form', [
            'room' => $room,
            'types' => RoomType::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $room->room_type_id))
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validate($this->rules($room));

        try {
            $this->rooms->updateRoom($room, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['room' => $e->getMessage()]);
        }

        return redirect()->route('admin.rooms.index')->with('success', "Room {$room->room_number} updated.");
    }

    public function block(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['BLOCKED', 'MAINTENANCE', 'RANGE'])],
            'blocked_from' => ['nullable', 'required_if:mode,RANGE', 'date_format:Y-m-d'],
            'blocked_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:blocked_from'],
            'block_reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'blocked_from.required_if' => 'A date-ranged block needs a start date.',
            'block_reason.required' => 'Record why the room is out of service.',
        ]);

        $status = $data['mode'] === 'RANGE' ? RoomStatus::ACTIVE : RoomStatus::from($data['mode']);
        $isRange = $data['mode'] === 'RANGE';

        try {
            $this->rooms->block(
                $room,
                $status,
                $isRange ? $data['blocked_from'] : null,
                $isRange ? ($data['blocked_to'] ?? null) : null,
                $data['block_reason'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['room' => $e->getMessage()]);
        }

        return back()->with('success', "Room {$room->room_number} taken out of service.");
    }

    public function unblock(Request $request, Room $room): RedirectResponse
    {
        try {
            $this->rooms->unblock($room, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['room' => $e->getMessage()]);
        }

        return back()->with('success', "Room {$room->room_number} is back in service.");
    }

    /** @return array<string, mixed> */
    private function rules(?Room $room): array
    {
        return [
            'room_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/]+$/',
                Rule::unique('rooms', 'room_number')->ignore($room?->id)],
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')],
            'floor' => ['nullable', 'integer', 'between:-2,50'],
            'block' => ['nullable', 'string', 'max:30'],
            'capacity' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
