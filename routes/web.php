<?php

declare(strict_types=1);

use App\Http\Controllers\Adg\ApprovalController;
use App\Http\Controllers\Admin\AllotmentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GoogleMailController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\IdProofRevealController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\RoomTypeController;
use App\Http\Controllers\Admin\StayController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Manager\ReviewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\Shared\DocumentController;
use App\Http\Controllers\User\BookingRequestController;
use App\Http\Controllers\User\ExtensionController;
use App\Http\Controllers\User\FeedbackController;
use App\Http\Controllers\User\LetterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes — see docs/ROUTES.md for the authoritative map.
|--------------------------------------------------------------------------
| Two absences are deliberate and must not be "fixed":
|
|   1. There is no self-registration route. Accounts are provisioned by an
|      administrator (SECURITY.md section 3). The public requisition form
|      below creates requests, never accounts: it only accepts an employee who
|      already exists.
|
|   2. There is no more-info route under /adg. The ADG may approve or reject
|      only (PLAN.md decision 5). The route's absence is one of three layers
|      enforcing that; the others are the missing permission and the missing
|      state transition.
*/

// ---------------------------------------------------------------- guest routes

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ------------------------------------------------------- public requisition
// The landing page offers two entry points: the requisition form, which
// employees submit without signing in (identified by Employee ID / PPO No. +
// registered mobile), and staff sign-in. Only submission is public; tracking, editing and every approval screen remain
// behind auth. Throttled per IP here, and per Employee ID in the controller.

Route::view('/', 'public.landing')->name('landing');
Route::get('/book', [PublicBookingController::class, 'create'])->name('public.booking');
Route::post('/book', [PublicBookingController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.booking.store');
Route::get('/book/submitted', [PublicBookingController::class, 'submitted'])->name('public.booking.submitted');

// ------------------------------------------------------------ authenticated

Route::middleware('auth')->group(function () {

    // Sends each role to its own work queue rather than a shared dashboard.
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    // --- Notifications (live bell) ---------------------------------------
    // The feed is polled on a timer by the header bell, which is how the UI
    // updates without a page refresh.
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // --- User (Employee) -------------------------------------------------
    // No availability route exists in this group. Core Rule 2.
    Route::middleware('role:user,manager,adg,admin')->prefix('my')->name('my.')->group(function () {
        Route::get('/requests', [BookingRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/create', [BookingRequestController::class, 'create'])->name('requests.create');
        Route::post('/requests', [BookingRequestController::class, 'store'])->name('requests.store');
        Route::get('/requests/{bookingRequest}', [BookingRequestController::class, 'show'])->name('requests.show');
        Route::get('/requests/{bookingRequest}/edit', [BookingRequestController::class, 'edit'])->name('requests.edit');
        Route::put('/requests/{bookingRequest}', [BookingRequestController::class, 'update'])->name('requests.update');
        Route::post('/requests/{bookingRequest}/resubmit', [BookingRequestController::class, 'resubmit'])->name('requests.resubmit');
        Route::post('/requests/{bookingRequest}/cancel', [BookingRequestController::class, 'cancel'])->name('requests.cancel');
        Route::post('/requests/{bookingRequest}/extension', [ExtensionController::class, 'store'])->name('requests.extension');
        Route::get('/requests/{bookingRequest}/letter', [LetterController::class, 'show'])->name('requests.letter');
        Route::get('/requests/{bookingRequest}/feedback', [FeedbackController::class, 'create'])->name('requests.feedback');
        Route::post('/requests/{bookingRequest}/feedback', [FeedbackController::class, 'store'])->name('requests.feedback.store');
    });

    // --- Identity documents ----------------------------------------------
    // The only path to a stored ID proof. Policy-gated and audited; the files
    // themselves have no URL (private disk, serving disabled).
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    // --- Manager ---------------------------------------------------------
    Route::middleware('role:manager')->prefix('manager')->name('manager.')->group(function () {
        Route::get('/requests', [ReviewController::class, 'index'])->name('requests.index');
        Route::get('/requests/{bookingRequest}', [ReviewController::class, 'show'])->name('requests.show');
        Route::post('/requests/{bookingRequest}/approve', [ReviewController::class, 'approve'])->name('requests.approve');
        Route::post('/requests/{bookingRequest}/reject', [ReviewController::class, 'reject'])->name('requests.reject');
        Route::post('/requests/{bookingRequest}/more-info', [ReviewController::class, 'moreInfo'])->name('requests.moreInfo');
        // No inventory page: the Manager sees rooms on the review screen's
        // room board, for the request in front of them (PLAN.md decision 10).
    });

    // --- ADG -------------------------------------------------------------
    // Approve and reject ONLY. There is no more-info route here, and adding one
    // would violate PLAN.md decision 5. See Adg\ApprovalController for the four
    // layers that enforce this.
    Route::middleware('role:adg')->prefix('adg')->name('adg.')->group(function () {
        Route::get('/requests', [ApprovalController::class, 'index'])->name('requests.index');
        Route::get('/requests/{bookingRequest}', [ApprovalController::class, 'show'])->name('requests.show');
        Route::post('/requests/{bookingRequest}/approve', [ApprovalController::class, 'approve'])->name('requests.approve');
        Route::post('/requests/{bookingRequest}/reject', [ApprovalController::class, 'reject'])->name('requests.reject');
    });

    // --- Booking operations (Manager and Admin) ----------------------------
    // The single Manager runs the whole booking operation: availability,
    // allotment, check-in / check-out and stay extensions. The Administrator
    // keeps access too. URLs and route names stay under /admin so every
    // existing screen and link keeps working.
    Route::middleware('role:manager,admin')->prefix('admin')->name('admin.')->group(function () {

        // Screen 3.3 — aborts 409 unless the request has been approved.
        Route::get('/requests/{bookingRequest}/availability', [AvailabilityController::class, 'show'])
            ->name('availability.show');

        // Screen 3.5 and the allotment queue.
        Route::get('/allotments', [AllotmentController::class, 'index'])->name('allotments.index');
        Route::get('/requests/{bookingRequest}/allot', [AllotmentController::class, 'create'])->name('allotments.create');
        Route::post('/requests/{bookingRequest}/allot', [AllotmentController::class, 'store'])->name('allotments.store');
        Route::get('/requests/{bookingRequest}/allotment', [AllotmentController::class, 'show'])->name('allotments.show');
        Route::post('/requests/{bookingRequest}/no-room', [AllotmentController::class, 'markNoRoom'])->name('allotments.noRoom');
        Route::post('/requests/{bookingRequest}/recheck', [AllotmentController::class, 'recheck'])->name('allotments.recheck');
        Route::delete('/allotments/{allotment}', [AllotmentController::class, 'destroy'])->name('allotments.destroy');
        Route::get('/allotments/{allotment}/letter', [AllotmentController::class, 'letter'])->name('allotments.letter');

        // --- Stay lifecycle (Phase 6) ---
        Route::get('/stays', [StayController::class, 'index'])->name('stays.index');
        Route::post('/requests/{bookingRequest}/check-in', [StayController::class, 'checkIn'])->name('stays.checkIn');
        Route::post('/requests/{bookingRequest}/check-out', [StayController::class, 'checkOut'])->name('stays.checkOut');
        Route::post('/stays/qr', [StayController::class, 'qrCheckIn'])->name('stays.qr');
        Route::post('/extensions/{extension}/approve', [StayController::class, 'approveExtension'])->name('extensions.approve');
        Route::post('/extensions/{extension}/deny', [StayController::class, 'denyExtension'])->name('extensions.deny');
    });

    // --- Admin -----------------------------------------------------------
    // System setup only: masters, settings, templates, audit, mail.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {

        // --- Masters ---
        // No destroy routes: users and rooms are referenced by requests,
        // allotments and audit rows, so they are deactivated / blocked instead.
        // Tariffs have no edit either: a rate is revised by adding a new row.
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('rooms', RoomController::class)->except(['show', 'destroy']);
        Route::post('/rooms/{room}/block', [RoomController::class, 'block'])->name('rooms.block');
        Route::post('/rooms/{room}/unblock', [RoomController::class, 'unblock'])->name('rooms.unblock');
        Route::resource('room-types', RoomTypeController::class)->except(['show', 'destroy'])
            ->parameters(['room-types' => 'roomType']);
        Route::get('/tariffs', [TariffController::class, 'index'])->name('tariffs.index');
        Route::post('/tariffs', [TariffController::class, 'store'])->name('tariffs.store');
        Route::post('/tariffs/{tariff}/end', [TariffController::class, 'end'])->name('tariffs.end');
        Route::resource('holidays', HolidayController::class)->only(['index', 'store', 'update', 'destroy']);

        // --- Settings, templates, audit (Phase 9) ---
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // Edit only: the 19 templates are fixed by NotificationEvent.
        Route::resource('email-templates', EmailTemplateController::class)->only(['index', 'edit', 'update'])
            ->parameters(['email-templates' => 'emailTemplate']);
        // Read-only. There is deliberately no write route for audit_logs.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // Audited reveal of a full identity number (SECURITY.md section 2).
        Route::post('/occupants/{occupant}/reveal', IdProofRevealController::class)
            ->middleware('throttle:10,1')
            ->name('occupants.reveal');

        // --- Outbound mail setup (Gmail API) ---
        // The consent round trip needs a human at a browser, so this is a one-time
        // administrator screen rather than something the app can do unattended.
        Route::get('/mail/google', [GoogleMailController::class, 'show'])->name('mail.google');
        Route::post('/mail/google/test', [GoogleMailController::class, 'test'])->name('mail.google.test');
        Route::post('/mail/google/disconnect', [GoogleMailController::class, 'disconnect'])->name('mail.google.disconnect');
    });

    // The OAuth round trip sits outside the /admin prefix because the redirect URI
    // registered with Google is /oauth/google/callback. Both ends are still
    // admin-only.
    Route::middleware('role:admin')->group(function () {
        Route::get('/oauth/google/redirect', [GoogleMailController::class, 'redirect'])->name('oauth.google.redirect');
        Route::get('/oauth/google/callback', [GoogleMailController::class, 'callback'])->name('oauth.google.callback');
    });

    Route::middleware('role:admin')->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
