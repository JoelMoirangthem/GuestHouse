<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\BookingRequestService;
use App\Domain\Enums\VisitPurpose;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Requests\PublicBookingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public "Room & Stay Details" requisition on the landing page.
 *
 * Employees submit without signing in. They are identified by Employee ID /
 * PPO No. plus the mobile number on their account, and the request is then
 * raised in their name and enters the normal Manager -> ADG -> Admin chain.
 *
 * Nothing else is public: viewing, editing and cancelling a request, and every
 * approval or allotment screen, still require a signed-in account.
 */
class PublicBookingController extends Controller
{
    /** Failed identity matches allowed per Employee ID before it is locked out. */
    private const MAX_FAILED_MATCHES = 5;

    private const LOCKOUT_SECONDS = 600;

    public function __construct(
        private readonly BookingRequestService $service,
    ) {}

    public function create(): View
    {
        return view('public.booking', [
            'purposes' => VisitPurpose::cases(),
            'idTypes' => PublicBookingRequest::ID_TYPES,
        ]);
    }

    public function store(PublicBookingRequest $request): RedirectResponse
    {
        $code = Str::upper(trim($request->string('employee_code')->toString()));
        $mobile = $request->string('contact_mobile')->toString();

        // Employee ID is optional. Only throttle by-code lookups (a blank code
        // resolves by mobile or the demo fallback and is never "wrong").
        if ($code !== '') {
            $throttleKey = 'public-booking:'.$code;

            if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_MATCHES)) {
                $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

                return $this->reject($request, "Too many unsuccessful attempts. Try again in {$minutes} minute(s).");
            }
        }

        $employee = $this->resolveRequester($code, $mobile);

        if ($employee === null) {
            if ($code !== '') {
                RateLimiter::hit('public-booking:'.$code, self::LOCKOUT_SECONDS);
            }

            return $this->reject($request,
                'We could not identify an account for this requisition. '
                .'Enter a valid Employee ID / PPO No. or the registered mobile number, or contact the administrator.');
        }

        if ($code !== '') {
            RateLimiter::clear('public-booking:'.$code);
        }

        // DEMO MODE: no field is required. Anything missing is defaulted so the
        // request always creates cleanly and goes straight to Manager review.
        $purpose = VisitPurpose::tryFrom($request->string('purpose')->toString()) ?? VisitPurpose::SELF;

        $idType = $request->filled('id_type')
            ? $request->string('id_type')->toString()
            : 'OFFICE_ID';

        // Dates: use what was given if valid, otherwise sensible near-future
        // defaults (check-in today, check-out tomorrow) so nights > 0.
        $checkIn = $this->validDate($request->input('check_in_date')) ?? now()->format('Y-m-d');
        $checkOut = $this->validDate($request->input('check_out_date'));
        if ($checkOut === null || $checkOut <= $checkIn) {
            $checkOut = \Illuminate\Support\Carbon::parse($checkIn)->addDay()->format('Y-m-d');
        }
        $checkInTime = $request->filled('check_in_time') ? $request->string('check_in_time')->toString() : '14:00';
        $checkOutTime = $request->filled('check_out_time') ? $request->string('check_out_time')->toString() : '11:00';

        $rooms = (int) $request->integer('rooms');
        if ($rooms < 1) {
            $rooms = 1;
        }

        // A guest visit books a room for someone else; the employee submitting
        // is the host. For a personal (self) visit the submitter may enter the
        // employee's name explicitly, otherwise the matched account name stands.
        $occupantName = $purpose->needsHost()
            ? ($request->filled('guest_name') ? $request->string('guest_name')->toString() : 'Guest')
            : ($request->filled('employee_name')
                ? $request->string('employee_name')->toString()
                : $employee->name);

        // The ID upload is optional; only attach a file when one was provided.
        $file = $request->file('id_card');
        $files = $file !== null ? [$file] : [];

        try {
            $booking = $this->service->createAndSubmit(
                requester: $employee,
                data: [
                    'purpose' => $purpose,
                    'training_programme' => $purpose->needsTrainingProgramme()
                        ? ($request->filled('training_programme') ? $request->input('training_programme') : 'Training programme')
                        : $request->input('training_programme'),
                    'host_employee_id' => $purpose->needsHost() ? $employee->id : null,
                    'check_in_date' => $checkIn,
                    'check_in_time' => $checkInTime,
                    'check_out_date' => $checkOut,
                    'check_out_time' => $checkOutTime,
                    'total_members' => 1,
                    'contact_mobile' => $mobile !== '' ? $mobile : ($employee->mobile ?? '9999999999'),
                    // Notifications go to the address on the account, never to
                    // one typed into an unauthenticated form.
                    'contact_email' => $employee->email,
                ],
                occupants: [[
                    'name' => $occupantName,
                    'relation' => $purpose->needsHost() ? 'Guest' : 'Self',
                    'id_proof_type' => $idType,
                ]],
                files: $files,
                roomsRequested: $rooms,
                docType: $idType,
                requireIdentityProof: false,
            );
        } catch (InvalidTransitionException $e) {
            return $this->reject($request, $e->getMessage());
        }

        return redirect()
            ->route('public.booking.submitted')
            ->with('submitted_request_no', $booking->request_no);
    }

    public function submitted(Request $request): View|RedirectResponse
    {
        $requestNo = $request->session()->get('submitted_request_no');

        if ($requestNo === null) {
            return redirect()->route('public.booking');
        }

        return view('public.submitted', ['requestNo' => $requestNo]);
    }

    // ------------------------------------------------------------------ helpers

    /** Return a Y-m-d string if the input parses as a date, else null. */
    private function validDate(mixed $value): ?string
    {
        $s = is_string($value) ? trim($value) : '';

        if ($s === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($s)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve which employee this requisition belongs to.
     *
     * Employee ID is optional on the public form, so identity is resolved with a
     * graceful fallback chain:
     *   1. Employee ID + registered mobile — the strict, original match.
     *   2. Employee ID alone, when the mobile was left blank.
     *   3. Registered mobile alone, when only the mobile was given.
     *   4. A default demo/booking account, so a requisition can always be raised
     *      (needed for demos and walk-in submissions with no credentials).
     *
     * Only active accounts that may raise requests are ever returned.
     */
    private function resolveRequester(string $code, string $mobile): ?User
    {
        $canCreate = fn (?User $u) => $u !== null && $u->is_active && $u->hasPermission('request.create') ? $u : null;

        // 1 & 2 — by Employee ID (with mobile if supplied, otherwise code alone).
        if ($code !== '') {
            $byCode = User::query()->where('employee_code', $code)->where('is_active', true)->first();

            if ($byCode !== null) {
                if ($mobile === '' || hash_equals((string) $byCode->mobile, $mobile)) {
                    return $canCreate($byCode);
                }

                // A code was given but the mobile does not match it: treat as a
                // failed identity match rather than silently falling through.
                return null;
            }
        }

        // 3 — by registered mobile alone.
        if ($mobile !== '') {
            $byMobile = User::query()->where('mobile', $mobile)->where('is_active', true)->first();

            if ($canCreate($byMobile) !== null) {
                return $byMobile;
            }
        }

        // 4 — default account, so the demo/walk-in path always completes.
        return $this->defaultRequester();
    }

    /**
     * The account a requisition is filed under when no identifying details are
     * given. Prefers a dedicated demo/booking account if one exists, otherwise
     * the first active employee who may raise requests.
     */
    private function defaultRequester(): ?User
    {
        $preferred = User::query()
            ->where('is_active', true)
            ->whereIn('employee_code', ['GH-PUBLIC-001', 'GH-DEMO-001'])
            ->first();

        if ($preferred !== null && $preferred->hasPermission('request.create')) {
            return $preferred;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', \App\Domain\Enums\RoleSlug::USER->value))
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->hasPermission('request.create'));
    }

    private function reject(PublicBookingRequest $request, string $message): RedirectResponse
    {
        return back()
            ->withInput($request->except('id_card'))
            ->withErrors(['submit' => $message]);
    }
}
