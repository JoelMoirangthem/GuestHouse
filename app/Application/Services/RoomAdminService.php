<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\RoomStatus;
use App\Domain\Enums\VisitPurpose;
use App\Models\Allotment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Room, room-type and tariff masters — ROUTES.md "Masters", PLAN.md decision 4.
 *
 * Rules a naive CRUD form would miss:
 *
 *   - Blocking a room that already holds an allotment in the block window would
 *     leave an approved officer with a letter for a room the desk cannot give
 *     them. The block is refused and the conflicting allotments are named, so
 *     the administrator can release or move them first.
 *   - A room's type cannot change while it holds a live allotment: the rate was
 *     snapshotted against the old type and the letter already names it.
 *   - Tariffs are history, not settings. A rate is never edited in place;
 *     a revision is a new row, and the previous open-ended row is closed the day
 *     before the new one starts, so every date resolves to exactly one rate and
 *     last quarter's figures cannot move.
 */
class RoomAdminService
{
    /** Far-future sentinel for an open-ended window, matching the availability SQL. */
    private const OPEN_END = '9999-12-31';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------------- rooms

    /** @param  array<string, mixed>  $data */
    public function createRoom(array $data, User $admin): Room
    {
        $this->assertAdmin($admin, 'master.manage');

        return DB::transaction(function () use ($data, $admin) {
            $room = Room::create([
                ...$this->roomFields($data),
                'status' => RoomStatus::ACTIVE->value,
            ]);

            $this->audit->record($room, 'ROOM_CREATED', $admin, metadata: [
                'room_number' => $room->room_number,
                'room_type_id' => $room->room_type_id,
            ]);

            return $room;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateRoom(Room $room, array $data, User $admin): Room
    {
        $this->assertAdmin($admin, 'master.manage');

        return DB::transaction(function () use ($room, $data, $admin) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $fields = $this->roomFields($data);

            if ((int) $fields['room_type_id'] !== $room->room_type_id
                && $this->liveAllotments($room, today()->format('Y-m-d'), self::OPEN_END)->isNotEmpty()) {
                throw new RuntimeException(
                    "Room {$room->room_number} holds a current or future allotment, so its type cannot change. "
                    .'Release that allotment first.'
                );
            }

            $room->fill($fields);
            $changed = array_values(array_diff(array_keys($room->getDirty()), ['updated_at']));
            $room->save();

            if ($changed !== []) {
                $this->audit->record($room, 'ROOM_UPDATED', $admin, metadata: ['fields' => $changed]);
            }

            return $room;
        });
    }

    /**
     * Take a room out of service.
     *
     * Two shapes, matching the two columns the availability query honours:
     *   - $status BLOCKED or MAINTENANCE: indefinite, until unblocked
     *   - $status ACTIVE with a from date: a date-ranged block; $to may be null
     *     for an open-ended block
     */
    public function block(Room $room, RoomStatus $status, ?string $from, ?string $to, string $reason, User $admin): Room
    {
        $this->assertAdmin($admin, 'room.block');

        if ($status === RoomStatus::ACTIVE && $from === null) {
            throw new RuntimeException('A date-ranged block needs a start date.');
        }

        if ($from !== null && $to !== null && $to < $from) {
            throw new RuntimeException('The block cannot end before it starts.');
        }

        return DB::transaction(function () use ($room, $status, $from, $to, $reason, $admin) {
            // Lock the room first. AllotmentService locks rooms before inserting,
            // so a concurrent allotment waits here rather than slipping into the
            // window between this check and the write.
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            // A status block applies from today with no end. A date block covers
            // [from, to] inclusive, which as a half-open window is [from, to + 1).
            $windowFrom = $status === RoomStatus::ACTIVE ? $from : today()->format('Y-m-d');
            $windowTo = ($status === RoomStatus::ACTIVE && $to !== null)
                ? Carbon::parse($to)->addDay()->format('Y-m-d')
                : self::OPEN_END;

            $conflicts = $this->liveAllotments($room, $windowFrom, $windowTo);

            if ($conflicts->isNotEmpty()) {
                throw new RuntimeException(
                    "Room {$room->room_number} is allotted during that period ("
                    .$conflicts->pluck('allotment_no')->implode(', ')
                    .'). Release or move those allotments before blocking the room.'
                );
            }

            $room->status = $status;
            $room->block_reason = $reason;
            $room->blocked_from = $status === RoomStatus::ACTIVE ? $from : null;
            $room->blocked_to = $status === RoomStatus::ACTIVE ? $to : null;
            $room->save();

            $this->audit->record($room, 'ROOM_BLOCKED', $admin, remarks: $reason, metadata: array_filter([
                'room_number' => $room->room_number,
                'status' => $status->value,
                'from' => $from,
                'to' => $to,
            ]));

            return $room;
        });
    }

    public function unblock(Room $room, User $admin): Room
    {
        $this->assertAdmin($admin, 'room.block');

        return DB::transaction(function () use ($room, $admin) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($room->status === RoomStatus::ACTIVE && $room->blocked_from === null) {
                throw new RuntimeException("Room {$room->room_number} is not blocked.");
            }

            $room->status = RoomStatus::ACTIVE;
            $room->block_reason = null;
            $room->blocked_from = null;
            $room->blocked_to = null;
            $room->save();

            $this->audit->record($room, 'ROOM_UNBLOCKED', $admin, metadata: ['room_number' => $room->room_number]);

            return $room;
        });
    }

    // --------------------------------------------------------------- room types

    /** @param  array<string, mixed>  $data */
    public function saveRoomType(?RoomType $type, array $data, User $admin): RoomType
    {
        $this->assertAdmin($admin, 'master.manage');

        return DB::transaction(function () use ($type, $data, $admin) {
            $creating = $type === null;
            $type ??= new RoomType;

            $type->fill([
                'code' => strtoupper((string) $data['code']),
                'name' => $data['name'],
                'category' => $data['category'],
                'default_capacity' => (int) $data['default_capacity'],
                'has_ac' => (bool) ($data['has_ac'] ?? false),
                'description' => $data['description'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);

            $changed = array_values(array_diff(array_keys($type->getDirty()), ['updated_at', 'created_at']));
            $type->save();

            if ($creating || $changed !== []) {
                $this->audit->record($type, $creating ? 'ROOM_TYPE_CREATED' : 'ROOM_TYPE_UPDATED', $admin, metadata: [
                    'code' => $type->code,
                    'fields' => $creating ? null : $changed,
                ]);
            }

            return $type;
        });
    }

    // ------------------------------------------------------------------ tariffs

    /**
     * Add a rate revision.
     *
     * The open-ended row for the same type and purpose (if any) is closed the day
     * before this one starts. A revision starting on or before an existing row's
     * start date is refused: it would leave two rows claiming the same days, and
     * which one "won" would depend on sort order rather than on intent.
     *
     * @param  array<string, mixed>  $data
     */
    public function addTariff(array $data, User $admin): Tariff
    {
        $this->assertAdmin($admin, 'master.manage');

        return DB::transaction(function () use ($data, $admin) {
            $typeId = (int) $data['room_type_id'];
            $purpose = filled($data['purpose'] ?? null) ? VisitPurpose::from((string) $data['purpose']) : null;
            $from = (string) $data['effective_from'];
            $to = filled($data['effective_to'] ?? null) ? (string) $data['effective_to'] : null;

            $sameSeries = Tariff::query()
                ->where('room_type_id', $typeId)
                ->where('is_active', true)
                ->when($purpose, fn ($q) => $q->where('purpose', $purpose->value), fn ($q) => $q->whereNull('purpose'))
                ->lockForUpdate()
                ->get();

            if ($sameSeries->contains(fn (Tariff $t) => $t->effective_from->format('Y-m-d') >= $from)) {
                throw new RuntimeException(
                    'A tariff for this room type and purpose already starts on or after that date. '
                    .'Choose a later start date.'
                );
            }

            $overlapsClosed = $sameSeries->contains(fn (Tariff $t) => $t->effective_to !== null
                && $t->effective_to->format('Y-m-d') >= $from);

            if ($overlapsClosed) {
                throw new RuntimeException('That start date falls inside an existing tariff period.');
            }

            $closed = [];
            foreach ($sameSeries->whereNull('effective_to') as $open) {
                $open->effective_to = Carbon::parse($from)->subDay();
                $open->save();
                $closed[] = $open->id;
            }

            $tariff = Tariff::create([
                'room_type_id' => $typeId,
                'purpose' => $purpose?->value,
                'amount_per_night' => $data['amount_per_night'],
                'effective_from' => $from,
                'effective_to' => $to,
                'is_active' => true,
            ]);

            $this->audit->record($tariff, 'TARIFF_CREATED', $admin, metadata: [
                'room_type_id' => $typeId,
                'purpose' => $purpose?->value,
                'amount_per_night' => (string) $tariff->amount_per_night,
                'effective_from' => $from,
                'effective_to' => $to,
                'closed_tariff_ids' => $closed ?: null,
            ]);

            return $tariff;
        });
    }

    /**
     * End an open tariff on a given date (inclusive). Rates already snapshotted
     * on allotments are untouched; only future resolution changes.
     */
    public function endTariff(Tariff $tariff, string $on, User $admin): Tariff
    {
        $this->assertAdmin($admin, 'master.manage');

        return DB::transaction(function () use ($tariff, $on, $admin) {
            $tariff = Tariff::whereKey($tariff->id)->lockForUpdate()->firstOrFail();

            if ($on < $tariff->effective_from->format('Y-m-d')) {
                throw new RuntimeException('A tariff cannot end before it starts.');
            }

            if ($tariff->effective_to !== null && $tariff->effective_to->format('Y-m-d') <= $on) {
                throw new RuntimeException('This tariff already ends on or before that date.');
            }

            $previous = $tariff->effective_to?->format('Y-m-d');
            $tariff->effective_to = $on;
            $tariff->save();

            $this->audit->record($tariff, 'TARIFF_ENDED', $admin, metadata: [
                'from_effective_to' => $previous,
                'to_effective_to' => $on,
            ]);

            return $tariff;
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Allotments that hold this room at any point in [from, to).
     *
     * @return \Illuminate\Support\Collection<int, Allotment>
     */
    private function liveAllotments(Room $room, string $from, string $to)
    {
        return Allotment::query()
            ->where('room_id', $room->id)
            ->occupying()
            ->overlapping($from, $to)
            ->orderBy('check_in_date')
            ->get(['id', 'allotment_no', 'check_in_date', 'check_out_date']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function roomFields(array $data): array
    {
        return [
            'room_number' => trim((string) $data['room_number']),
            'room_type_id' => (int) $data['room_type_id'],
            'floor' => filled($data['floor'] ?? null) ? (int) $data['floor'] : null,
            'block' => $data['block'] ?? null,
            // Blank means "use the type's default", which is how the column is read.
            'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function assertAdmin(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException('Only an administrator may manage rooms and tariffs.');
        }
    }
}
