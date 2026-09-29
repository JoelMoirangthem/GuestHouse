<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\ReportResult;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\RoomStatus;
use App\Domain\Enums\VisitPurpose;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Dashboard figures and the seven reports — REPORTS.md.
 *
 * Every formula in the system lives here, so two screens can never compute the
 * same metric two ways. All queries are read-only (REPORTS.md section 5).
 *
 * PERIOD SEMANTICS. The spec says "in the selected period" without saying which
 * date anchors each record. The choices, applied consistently:
 *
 *   - a request belongs to the period of its submitted_at date
 *   - occupancy and room-nights count the nights that fall inside [from, to]
 *   - an allotment row is listed if its stay overlaps [from, to]
 *   - revenue is attributed to the checkout date (actual for completed stays,
 *     scheduled for projected ones), so a stay spanning two months is counted
 *     once, never in both
 *   - queue tiles (Pending Manager / ADG / Allotment) are CURRENT counts, not
 *     period counts: a queue is "what is waiting now"
 *
 * EARLY CHECKOUT CORRECTION. REPORTS.md section 2 counts a night as occupied
 * while `day < check_out_date`. After an early departure check_out_date still
 * holds the SCHEDULED date, but the room was released and may already be
 * re-allotted, so the literal formula would count the same room twice and
 * could exceed 100%. For EARLY_CHECKOUT rows the stay therefore ends on the
 * actual departure date, never earlier than one night — the same minimum the
 * guest is billed for (REPORTS.md section 3).
 */
class ReportService
{
    /** type => [title, admin-only] */
    public const TYPES = [
        'occupancy' => ['Occupancy Report', false],
        'booking' => ['Booking Report', false],
        'allotment' => ['Allotment Report', false],
        'user-wise' => ['User-wise Report', false],
        'purpose-wise' => ['Purpose-wise Report', false],
        'revenue' => ['Revenue Report', true],
        'feedback' => ['Feedback Report', false],
    ];

    /** Reports whose rows a Manager sees only for their own reportees — REPORTS.md section 6. */
    private const TEAM_SCOPED = ['booking', 'allotment', 'user-wise'];

    /** Larger exports must be narrowed rather than generated in-request (REPORTS.md section 5). */
    public const EXPORT_ROW_LIMIT = 5000;

    /** Allotment statuses that represent a room actually given to a guest. */
    private const HELD = ['ALLOTTED', 'CHECKED_IN', 'CHECKED_OUT', 'EARLY_CHECKOUT'];

    private const REALISED = ['CHECKED_OUT', 'EARLY_CHECKOUT'];

    private const PROJECTED = ['ALLOTTED', 'CHECKED_IN'];

    // =================================================================== access

    public function canView(User $user, string $type): bool
    {
        if (! array_key_exists($type, self::TYPES)) {
            return false;
        }

        if (self::TYPES[$type][1]) {
            return $user->hasPermission('report.revenue');
        }

        return $user->hasPermission('report.view.all') || $user->hasPermission('report.view.team');
    }

    /** @return array<string, string> type => title, only those this user may open */
    public function availableFor(User $user): array
    {
        return collect(self::TYPES)
            ->filter(fn ($meta, $type) => $this->canView($user, $type))
            ->map(fn ($meta) => $meta[0])
            ->all();
    }

    // ================================================================ dashboard

    /**
     * @return array<string, int>
     */
    public function dashboardTiles(string $from, string $to): array
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        return [
            'total' => BookingRequest::query()
                ->where('status', '!=', RequestStatus::DRAFT->value)
                ->whereBetween('submitted_at', [$fromAt, $toAt])
                ->count(),
            'pending_manager' => BookingRequest::query()
                ->whereIn('status', [RequestStatus::PENDING_MANAGER->value, RequestStatus::MORE_INFO_MANAGER->value])
                ->count(),
            'pending_adg' => BookingRequest::where('status', RequestStatus::PENDING_ADG->value)->count(),
            'awaiting_allotment' => BookingRequest::query()->awaitingAllotment()->count(),
            // Rooms, not requests: one request can hold several (PLAN.md decision 1).
            'rooms_allotted' => Allotment::query()
                ->whereIn('status', self::HELD)
                ->whereBetween('allotted_at', [$fromAt, $toAt])
                ->count(),
            'failed_notifications' => Notification::where('status', 'FAILED')->count(),
        ];
    }

    /**
     * Occupancy per day — REPORTS.md section 2.
     *
     * The denominator is ACTIVE rooms only and ignores date-ranged blocks, so it
     * is stable across the month and days are comparable.
     *
     * @return array<int, array{date: string, occupied: int, pct: float}>
     */
    public function occupancyByDay(string $from, string $to): array
    {
        $activeRooms = Room::where('status', RoomStatus::ACTIVE->value)->count();
        $days = $this->days($from, $to);
        $counts = array_fill_keys($days, 0);

        foreach ($this->heldAllotmentsOverlapping($from, $to) as $a) {
            foreach ($this->nightsOf($a) as $night) {
                if (isset($counts[$night])) {
                    $counts[$night]++;
                }
            }
        }

        return array_map(fn (string $d) => [
            'date' => $d,
            'occupied' => $counts[$d],
            'pct' => $activeRooms > 0 ? round($counts[$d] / $activeRooms * 100, 1) : 0.0,
        ], $days);
    }

    /**
     * Room-type split of allotments made in the period — the dashboard pie.
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function allotmentsByRoomType(string $from, string $to): array
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $counts = Allotment::query()
            ->join('rooms', 'rooms.id', '=', 'allotments.room_id')
            ->whereIn('allotments.status', self::HELD)
            ->whereBetween('allotments.allotted_at', [$fromAt, $toAt])
            ->groupBy('rooms.room_type_id')
            ->selectRaw('rooms.room_type_id, COUNT(*) AS n')
            ->pluck('n', 'room_type_id');

        return RoomType::orderBy('sort_order')->get()
            ->map(fn (RoomType $t) => ['name' => $t->displayName(), 'count' => (int) ($counts[$t->id] ?? 0)])
            ->filter(fn ($r) => $r['count'] > 0)
            ->values()
            ->all();
    }

    // ================================================================== reports

    public function build(string $type, string $from, string $to, User $actor): ReportResult
    {
        if (! $this->canView($actor, $type)) {
            throw new RuntimeException('You are not permitted to view this report.');
        }

        if ($to < $from) {
            throw new RuntimeException('The end date cannot be before the start date.');
        }

        $result = match ($type) {
            'occupancy' => $this->occupancy($from, $to),
            'booking' => $this->booking($from, $to, $actor),
            'allotment' => $this->allotment($from, $to, $actor),
            'user-wise' => $this->userWise($from, $to, $actor),
            'purpose-wise' => $this->purposeWise($from, $to),
            'revenue' => $this->revenue($from, $to),
            'feedback' => $this->feedback($from, $to),
        };

        return $result;
    }

    /** 1. Occupancy, aggregated per room type. */
    private function occupancy(string $from, string $to): ReportResult
    {
        $days = count($this->days($from, $to));
        $types = RoomType::orderBy('sort_order')->get();
        $active = Room::where('status', RoomStatus::ACTIVE->value)
            ->selectRaw('room_type_id, COUNT(*) AS n')->groupBy('room_type_id')->pluck('n', 'room_type_id');

        $occupied = [];
        foreach ($this->heldAllotmentsOverlapping($from, $to) as $a) {
            $n = count(array_filter($this->nightsOf($a), fn ($d) => $d >= $from && $d <= $to));
            $occupied[$a->room->room_type_id] = ($occupied[$a->room->room_type_id] ?? 0) + $n;
        }

        $rows = [];
        $totAvail = 0;
        $totOcc = 0;
        foreach ($types as $t) {
            $avail = (int) ($active[$t->id] ?? 0) * $days;
            $occ = (int) ($occupied[$t->id] ?? 0);
            $totAvail += $avail;
            $totOcc += $occ;
            $rows[] = [
                'type' => $t->displayName(),
                'rooms' => (int) ($active[$t->id] ?? 0),
                'nights_available' => $avail,
                'nights_occupied' => $occ,
                'pct' => $this->pct($occ, $avail),
            ];
        }

        return new ReportResult(
            type: 'occupancy', title: self::TYPES['occupancy'][0], from: $from, to: $to,
            columns: ['type' => 'Room type', 'rooms' => 'Rooms in service', 'nights_available' => 'Nights available',
                'nights_occupied' => 'Nights occupied', 'pct' => 'Occupancy %'],
            rows: $rows,
            summary: ['Days in period' => $days, 'Room-nights available' => $totAvail,
                'Room-nights occupied' => $totOcc, 'Occupancy' => $this->pct($totOcc, $totAvail).'%'],
            notes: [
                'Occupancy = nights occupied ÷ (rooms in service × days). Rooms under a permanent block or maintenance are excluded from the base; date-ranged blocks are not, so a blocked night lowers occupancy.',
                'A night is occupied when check-in ≤ night < check-out. After an early check-out, the stay ends on the actual departure date.',
            ],
            numeric: ['rooms', 'nights_available', 'nights_occupied', 'pct'],
        );
    }

    /** 2. Booking — every submitted request. */
    private function booking(string $from, string $to, User $actor): ReportResult
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $requests = $this->scoped(BookingRequest::query(), $actor, 'booking')
            ->with('requester')
            ->withCount(['allotments as rooms_allotted' => fn ($q) => $q->whereIn('status', self::HELD)])
            ->where('status', '!=', RequestStatus::DRAFT->value)
            ->whereBetween('submitted_at', [$fromAt, $toAt])
            ->orderBy('submitted_at')
            ->get();

        $rows = $requests->map(fn (BookingRequest $r) => [
            'request_no' => $r->request_no,
            'requester' => $r->requester?->name,
            'purpose' => $r->purpose->label(),
            'check_in' => $r->check_in_date->format('d/m/Y'),
            'check_out' => $r->check_out_date->format('d/m/Y'),
            'nights' => (int) $r->nights,
            'members' => (int) $r->total_members,
            'status' => $r->status->label(),
            'manager_decision' => $this->decision($r->manager_acted_at, $r->status === RequestStatus::REJECTED_MANAGER),
            'adg_decision' => $this->decision($r->adg_acted_at, $r->status === RequestStatus::REJECTED_ADG),
            'rooms' => (int) $r->rooms_allotted,
        ])->all();

        return new ReportResult(
            type: 'booking', title: self::TYPES['booking'][0], from: $from, to: $to,
            columns: ['request_no' => 'Request no.', 'requester' => 'Requester', 'purpose' => 'Purpose',
                'check_in' => 'Check-in', 'check_out' => 'Check-out', 'nights' => 'Nights', 'members' => 'Members',
                'status' => 'Status', 'manager_decision' => 'Manager decision', 'adg_decision' => 'ADG decision',
                'rooms' => 'Rooms allotted'],
            rows: $rows,
            summary: ['Requests' => count($rows)],
            notes: ['Requests are included by the date they were submitted. Drafts are excluded.'],
            numeric: ['nights', 'members', 'rooms'],
            scopeLabel: $this->scopeLabel($actor, 'booking'),
        );
    }

    /** 3. Allotment — one row per room. */
    private function allotment(string $from, string $to, User $actor): ReportResult
    {
        $rows = Allotment::query()
            ->with(['room.roomType', 'bookingRequest.requester'])
            ->whereHas('bookingRequest', fn ($q) => $this->scoped($q, $actor, 'allotment'))
            ->where('check_in_date', '<=', $to)
            ->where('check_out_date', '>', $from)
            ->orderBy('check_in_date')->orderBy('allotment_no')
            ->get()
            ->map(fn (Allotment $a) => [
                'allotment_no' => $a->allotment_no,
                'request_no' => $a->bookingRequest?->request_no,
                'guest' => $a->bookingRequest?->requester?->name,
                'room' => $a->room?->room_number,
                'type' => $a->room?->roomType?->displayName(),
                'check_in' => $a->check_in_date->format('d/m/Y'),
                'check_out' => $a->check_out_date->format('d/m/Y'),
                'actual_in' => $a->actual_check_in_at?->format('d/m/Y H:i'),
                'actual_out' => $a->actual_check_out_at?->format('d/m/Y H:i'),
                'status' => $a->status->label(),
                'amount' => $this->money($a->total_amount),
            ])->all();

        return new ReportResult(
            type: 'allotment', title: self::TYPES['allotment'][0], from: $from, to: $to,
            columns: ['allotment_no' => 'Allotment no.', 'request_no' => 'Request no.', 'guest' => 'Guest',
                'room' => 'Room', 'type' => 'Type', 'check_in' => 'Check-in', 'check_out' => 'Check-out',
                'actual_in' => 'Actual in', 'actual_out' => 'Actual out', 'status' => 'Status', 'amount' => 'Amount (₹)'],
            rows: $rows,
            summary: ['Allotments' => count($rows)],
            notes: ['Every allotment whose stay overlaps the period, including released ones, so the list reconciles with the desk.'],
            numeric: ['amount'],
            scopeLabel: $this->scopeLabel($actor, 'allotment'),
        );
    }

    /** 4. User-wise — grouped by requester. */
    private function userWise(string $from, string $to, User $actor): ReportResult
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $requests = $this->scoped(BookingRequest::query(), $actor, 'user-wise')
            ->with(['requester', 'allotments'])
            ->where('status', '!=', RequestStatus::DRAFT->value)
            ->whereBetween('submitted_at', [$fromAt, $toAt])
            ->get();

        $rows = $requests->groupBy('user_id')->map(function (Collection $mine) {
            $user = $mine->first()->requester;
            $held = $mine->flatMap->allotments->filter(fn (Allotment $a) => in_array($a->status->value, self::HELD, true));

            return [
                'user' => $user?->name,
                'department' => $user?->department,
                'submitted' => $mine->count(),
                'approved' => $mine->filter(fn ($r) => $this->wasApproved($r))->count(),
                'rejected' => $mine->filter(fn ($r) => $this->wasRejected($r))->count(),
                'room_nights' => $held->sum(fn (Allotment $a) => count($this->nightsOf($a))),
                'amount' => $this->money($held->sum(fn (Allotment $a) => (float) $a->total_amount)),
            ];
        })->sortBy('user')->values()->all();

        return new ReportResult(
            type: 'user-wise', title: self::TYPES['user-wise'][0], from: $from, to: $to,
            columns: ['user' => 'User', 'department' => 'Department', 'submitted' => 'Requests', 'approved' => 'Approved',
                'rejected' => 'Rejected', 'room_nights' => 'Room-nights', 'amount' => 'Amount (₹)'],
            rows: $rows,
            summary: ['Users' => count($rows), 'Requests' => $requests->count()],
            notes: ['Approved = cleared both the Manager and the ADG. Room-nights: one allotment is one room, so 3 rooms × 2 nights = 6. Released allotments are excluded.'],
            numeric: ['submitted', 'approved', 'rejected', 'room_nights', 'amount'],
            scopeLabel: $this->scopeLabel($actor, 'user-wise'),
        );
    }

    /** 5. Purpose-wise — Training / Self / Guest. */
    private function purposeWise(string $from, string $to): ReportResult
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $requests = BookingRequest::query()
            ->with('allotments')
            ->where('status', '!=', RequestStatus::DRAFT->value)
            ->whereBetween('submitted_at', [$fromAt, $toAt])
            ->get();

        $rows = [];
        foreach (VisitPurpose::cases() as $purpose) {
            $mine = $requests->filter(fn ($r) => $r->purpose === $purpose);
            $approved = $mine->filter(fn ($r) => $this->wasApproved($r))->count();
            $decided = $approved + $mine->filter(fn ($r) => $this->wasRejected($r))->count();
            $allotments = $mine->flatMap->allotments;
            $held = $allotments->filter(fn (Allotment $a) => in_array($a->status->value, self::HELD, true));
            $realised = $allotments->filter(fn (Allotment $a) => in_array($a->status->value, self::REALISED, true));

            $rows[] = [
                'purpose' => $purpose->label(),
                'requests' => $mine->count(),
                'approval_rate' => $decided > 0 ? $this->pct($approved, $decided) : null,
                'avg_nights' => $mine->count() > 0 ? round($mine->avg('nights'), 1) : null,
                'room_nights' => $held->sum(fn (Allotment $a) => count($this->nightsOf($a))),
                'revenue' => $this->money($realised->sum(fn (Allotment $a) => (float) $a->total_amount)),
            ];
        }

        return new ReportResult(
            type: 'purpose-wise', title: self::TYPES['purpose-wise'][0], from: $from, to: $to,
            columns: ['purpose' => 'Purpose', 'requests' => 'Requests', 'approval_rate' => 'Approval rate %',
                'avg_nights' => 'Avg. nights', 'room_nights' => 'Room-nights', 'revenue' => 'Realised revenue (₹)'],
            rows: $rows,
            summary: ['Requests' => $requests->count()],
            notes: ['Approval rate = approved ÷ decided (approved + rejected); undecided and withdrawn requests are left out. Revenue counts completed stays only.'],
            numeric: ['requests', 'approval_rate', 'avg_nights', 'room_nights', 'revenue'],
        );
    }

    /** 6. Revenue — admin only; realised and projected are never summed. */
    private function revenue(string $from, string $to): ReportResult
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $realised = Allotment::query()->with('room.roomType')
            ->whereIn('status', self::REALISED)
            ->whereBetween('actual_check_out_at', [$fromAt, $toAt])
            ->get();

        $projected = Allotment::query()->with('room.roomType')
            ->whereIn('status', self::PROJECTED)
            ->whereBetween('check_out_date', [$from, $to])
            ->get();

        $rows = [];
        foreach (RoomType::orderBy('sort_order')->get() as $t) {
            $r = $realised->filter(fn ($a) => $a->room?->room_type_id === $t->id);
            $p = $projected->filter(fn ($a) => $a->room?->room_type_id === $t->id);
            $rows[] = [
                'type' => $t->displayName(),
                'stays' => $r->count(),
                'realised' => $this->money($r->sum(fn ($a) => (float) $a->total_amount)),
                'upcoming' => $p->count(),
                'projected' => $this->money($p->sum(fn ($a) => (float) $a->total_amount)),
            ];
        }

        return new ReportResult(
            type: 'revenue', title: self::TYPES['revenue'][0], from: $from, to: $to,
            columns: ['type' => 'Room type', 'stays' => 'Completed stays', 'realised' => 'Realised (₹)',
                'upcoming' => 'Open stays', 'projected' => 'Projected (₹)'],
            rows: $rows,
            summary: [
                'Realised' => '₹'.$this->money($realised->sum(fn ($a) => (float) $a->total_amount)),
                'Projected' => '₹'.$this->money($projected->sum(fn ($a) => (float) $a->total_amount)),
            ],
            notes: [
                'Realised = completed stays whose actual check-out falls in the period. Projected = allotted or in-residence stays due to check out in the period. The two are reported separately and must not be added together.',
                'Amounts use the rate snapshotted on each allotment, so a later tariff revision never changes these figures. Early check-outs are charged on nights actually stayed.',
            ],
            numeric: ['stays', 'realised', 'upcoming', 'projected'],
        );
    }

    /** 7. Feedback — averages, response rate and comments. */
    private function feedback(string $from, string $to): ReportResult
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        // Completed stays whose departure falls in the period, and the feedback
        // given for THOSE stays, so the response rate is a like-for-like ratio.
        $completed = BookingRequest::query()
            ->whereIn('status', [RequestStatus::CHECKED_OUT->value, RequestStatus::EARLY_CHECKOUT->value])
            ->whereHas('allotments', fn ($q) => $q->whereIn('status', self::REALISED)
                ->whereBetween('actual_check_out_at', [$fromAt, $toAt]))
            ->pluck('id');

        $feedback = Feedback::query()->with('bookingRequest.requester')
            ->whereIn('booking_request_id', $completed)
            ->orderBy('submitted_at')
            ->get();

        $rows = $feedback->map(fn (Feedback $f) => [
            'request_no' => $f->bookingRequest?->request_no,
            'guest' => $f->bookingRequest?->requester?->name,
            'submitted' => $f->submitted_at->format('d/m/Y'),
            'cleanliness' => $f->rating_cleanliness,
            'staff' => $f->rating_staff,
            'facilities' => $f->rating_facilities,
            'overall' => $f->rating_overall,
            'comments' => $f->comments,
        ])->all();

        $avg = fn (string $col) => $feedback->isEmpty() ? '—' : number_format((float) $feedback->avg($col), 2);

        return new ReportResult(
            type: 'feedback', title: self::TYPES['feedback'][0], from: $from, to: $to,
            columns: ['request_no' => 'Request no.', 'guest' => 'Guest', 'submitted' => 'Submitted',
                'cleanliness' => 'Cleanliness', 'staff' => 'Staff', 'facilities' => 'Facilities',
                'overall' => 'Overall', 'comments' => 'Comments'],
            rows: $rows,
            summary: [
                'Completed stays' => $completed->count(),
                'Responses' => $feedback->count(),
                'Response rate' => $this->pct($feedback->count(), $completed->count()).'%',
                'Avg. cleanliness' => $avg('rating_cleanliness'),
                'Avg. staff' => $avg('rating_staff'),
                'Avg. facilities' => $avg('rating_facilities'),
                'Avg. overall' => $avg('rating_overall'),
            ],
            notes: ['Response rate = feedback received ÷ stays completed in the period. Ratings are 1 (poor) to 5 (excellent).'],
            numeric: ['cleanliness', 'staff', 'facilities', 'overall'],
        );
    }

    // ================================================================ internals

    /**
     * Team scoping — REPORTS.md section 6: "team" = users whose
     * reporting_manager_id is the acting manager. The ADG and admin see all.
     */
    private function scoped(Builder $q, User $actor, string $type): Builder
    {
        if (in_array($type, self::TEAM_SCOPED, true) && ! $actor->hasPermission('report.view.all')) {
            $q->whereHas('requester', fn ($r) => $r->where('reporting_manager_id', $actor->id));
        }

        return $q;
    }

    private function scopeLabel(User $actor, string $type): ?string
    {
        return in_array($type, self::TEAM_SCOPED, true) && ! $actor->hasPermission('report.view.all')
            ? 'Your reportees only'
            : null;
    }

    /** @return Collection<int, Allotment> */
    private function heldAllotmentsOverlapping(string $from, string $to): Collection
    {
        return Allotment::query()
            ->with('room')
            ->whereIn('status', self::HELD)
            ->where('check_in_date', '<=', $to)
            ->where('check_out_date', '>', $from)
            ->get();
    }

    /**
     * The nights (Y-m-d) an allotment occupied its room, half-open [in, out).
     *
     * @return array<int, string>
     */
    public function nightsOf(Allotment $a): array
    {
        $in = $a->check_in_date->copy()->startOfDay();
        $out = $a->check_out_date->copy()->startOfDay();

        if ($a->status === AllotmentStatus::EARLY_CHECKOUT && $a->actual_check_out_at !== null) {
            $actual = Carbon::parse($a->actual_check_out_at)->startOfDay();
            $out = $actual->max($in->copy()->addDay())->min($out);
        }

        $nights = [];
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            $nights[] = $d->format('Y-m-d');
        }

        return $nights;
    }

    /** @return array<int, string> every date in [from, to], inclusive */
    private function days(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->format('Y-m-d') <= $to; $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /** @return array{0: string, 1: string} datetime bounds covering whole days */
    private function dayBounds(string $from, string $to): array
    {
        return [$from.' 00:00:00', $to.' 23:59:59'];
    }

    private function wasApproved(BookingRequest $r): bool
    {
        return $r->adg_acted_at !== null && $r->status !== RequestStatus::REJECTED_ADG;
    }

    private function wasRejected(BookingRequest $r): bool
    {
        return in_array($r->status, [RequestStatus::REJECTED_MANAGER, RequestStatus::REJECTED_ADG], true);
    }

    private function decision(?\DateTimeInterface $at, bool $rejected): string
    {
        if ($at === null) {
            return '—';
        }

        return ($rejected ? 'Rejected' : 'Approved').' '.$at->format('d/m/Y');
    }

    private function pct(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
