# Guest House Booking & Approval System — Architecture & Build Plan (v2, audited)

> Pragya Bhawan, NADT, RC, DTRTI, Lucknow — 226002
> Stack: Laravel (PHP) + MySQL + Bootstrap 5. SMTP, QR, PDF.
> v2 supersedes v1. Every item in the v1 audit is resolved here.

---

## 0. Core Design Rules (non-negotiable)

1. **Availability is checked by the ADMIN only, and only AFTER Manager + ADG approval.** *Exception, decision 10: the reviewing Manager sees the room board for that request's dates and may hold rooms with their approval.*
2. **Users NEVER see availability or room inventory.** Users declare *intent* only (dates, purpose, members).
3. Approval decides **entitlement**. Allotment decides **which rooms**. Separate acts, separate roles.
4. **A request may receive MULTIPLE rooms**, derived from the number of members. (Decision, locked.)
5. **ADG can only Approve or Reject** — no "More Info". Only the Manager has More Info, per screen 3.4. (Decision, locked.)

---

## 1. Actors

| Role | Capabilities |
|------|--------------|
| **User (Employee)** | Login, submit request (purpose, dates, members, ID proof), respond to More Info, view own status, check-in/out, feedback |
| **Manager** | Approve / Reject / **More Info** |
| **ADG** | **Approve / Reject only** |
| **Admin** | Check availability, allot rooms, block rooms, inventory, tariffs, settings, reports |

Enforced by policies + role middleware. No role can skip a stage.

---

## 2. Request Lifecycle — State Machine (corrected & complete)

```
DRAFT
 └─> PENDING_MANAGER            (user submits)
      ├─> MORE_INFO_MANAGER     (manager asks for info)
      │     └─> PENDING_MANAGER (user resubmits — RETURN PATH, was missing in v1)
      ├─> REJECTED_MANAGER      ● terminal
      └─> PENDING_ADG           (manager approves)
           ├─> REJECTED_ADG     ● terminal
           └─> PENDING_ALLOTMENT (ADG approves → admin may now check availability)
                ├─> NO_ROOM_AVAILABLE   (0 rooms found; waitlist or close) ●
                ├─> PARTIALLY_ALLOTTED  (some but not all rooms assigned)
                │     └─> ALLOTTED      (remaining rooms assigned)
                └─> ALLOTTED            (all required rooms assigned)
                     ├─> CANCELLED      ● terminal (before check-in)
                     └─> CHECKED_IN
                          ├─> CHECKED_OUT     ● terminal
                          ├─> EARLY_CHECKOUT  ● terminal
                          └─> EXTENSION_REQUESTED
                               ├─> CHECKED_IN (extension granted — rooms re-verified)
                               └─> CHECKED_IN (extension denied, original dates stand)
```

`PARTIALLY_ALLOTTED` exists only because of Decision 4: a 3-member request may find 2 rooms today and 1 tomorrow.

`EXTENSION_REQUESTED` closes the v1 gap. An extension is **not** a free action: it re-runs the availability check for the extra nights on the *already-allotted* rooms. If those rooms are taken, the admin either reallocates or denies.

Every transition: guard-checked → role-authorized → wrapped in a DB transaction → written to `audit_logs` → fires an event that may notify.

---

## 3. The Availability Rule (this was completely undefined in v1)

A room is **available** for the half-open interval `[from, to)` when all hold:

1. `room.status = ACTIVE` (not `BLOCKED`, not `MAINTENANCE`)
2. `room.capacity >= ` persons intended for that room
3. No overlapping allotment exists in status `ALLOTTED` or `CHECKED_IN`

**Overlap test:** `existing.check_in < new.check_out AND new.check_in < existing.check_out`

Half-open intervals are deliberate: a guest checking out on the 22nd frees the room for a guest checking in on the 22nd. Using closed intervals here would silently lose one night of capacity per booking.

### Rooms required from member count

```
rooms_needed = ceil(total_members / capacity_of_chosen_room_type)
```
The admin may override — a VIP officer may get a room alone. The computed number is a *suggestion*, never a hard constraint on the admin, because the infographic states allotment is at the discretion of the authority.

---

## 4. Concurrency Guard (the critical v1 defect)

Because availability is resolved late, two admins acting at once could double-book. Mandatory protections:

1. All allotment writes inside a single DB transaction.
2. Candidate rooms locked with `SELECT ... FOR UPDATE` before the overlap check.
3. Re-verify availability *after* acquiring the lock — never trust the pre-lock read.
4. Defensive guard: unique composite index on `(room_id, occupies)`, where `occupies` is a generated column holding `check_in_date` only while the allotment is active (SCHEMA.md §11). The database then rejects a duplicate active start date even if application logic is bypassed.

MySQL has no exclusion constraints (unlike Postgres), so steps 2–4 are not optional.


---

## 5. Architecture — Pragmatic SOLID (corrected from v1's over-engineering)

v1 wrapped every model in a repository interface. That is abstraction theatre in Laravel: Eloquent *is* the data-access abstraction, and the extra layer would have eaten the 3–4 week budget on plumbing. v2 keeps SOLID exactly where change is likely and removes it where it is not.

```
HTTP            Controllers · FormRequests · Blade views      (thin, no logic)
Application     Services · DTOs                               (ALL business logic)
Domain          Enums · StateMachine · Contracts              (framework-free rules)
Infrastructure  Adapters: Mail · SMS · PDF · QR + AvailabilityQuery
Persistence     Eloquent Models · Migrations · Seeders
```

**Kept abstract (real substitution happens here):**
- `NotificationChannel` — Email / SMS / InApp, add WhatsApp later with zero edits elsewhere
- `PdfGenerator`, `QrGenerator` — swappable libraries
- `AvailabilityQueryInterface` — the one query complex enough to isolate and unit-test hard

**Deliberately concrete:** plain CRUD. Services use Eloquent directly. No `EloquentUserRepository`.

### SOLID, applied honestly

- **S** — one service per business capability; controllers only translate HTTP.
- **O** — room types, tariffs, roles and email templates live in **database tables, not enums**, so admins extend the system without a deploy. (v1 contradicted itself by hardcoding these.)
- **L** — every `NotificationChannel` is substitutable; tests inject fakes.
- **I** — narrow contracts, one purpose each. No god-interface.
- **D** — Services depend on contracts for all external I/O, so no test ever sends a real SMS.

**Enums stay only for genuinely fixed domain values:** `RequestStatus`, `VisitPurpose` (Training/Self/Guest — fixed by policy), `RoomStatus`, `AllotmentStatus`.

**Policies moved out of Domain** into `app/Policies/`. v1 wrongly placed them in Domain; they depend on `Gate` and the User model, which is framework coupling. **`Actions/` removed** — v1 listed it in prose but never in the tree, and Services already cover it.

---

## 6. Folder Structure (v2)

```
final-gestHouse/
├── app/
│   ├── Domain/                          # framework-free
│   │   ├── Enums/
│   │   │   ├── RequestStatus.php
│   │   │   ├── VisitPurpose.php         # TRAINING | SELF | GUEST
│   │   │   ├── RoomStatus.php           # ACTIVE | BLOCKED | MAINTENANCE
│   │   │   └── AllotmentStatus.php      # ALLOTTED | CHECKED_IN | CHECKED_OUT | EARLY_CHECKOUT | CANCELLED
│   │   ├── StateMachine/
│   │   │   ├── RequestStateMachine.php  # transition table + guards
│   │   │   └── InvalidTransitionException.php
│   │   └── Contracts/
│   │       ├── AvailabilityQueryInterface.php
│   │       ├── NotificationChannel.php
│   │       ├── PdfGenerator.php
│   │       └── QrGenerator.php
│   │
│   ├── Application/
│   │   ├── Services/
│   │   │   ├── BookingRequestService.php    # create, resubmit-after-more-info
│   │   │   ├── ApprovalService.php          # manager + ADG decisions
│   │   │   ├── AvailabilityService.php      # ADMIN ONLY
│   │   │   ├── AllotmentService.php         # multi-room, locking, partial
│   │   │   ├── StayService.php              # check-in/out, extension, early-out
│   │   │   ├── FeedbackService.php
│   │   │   ├── NotificationDispatcher.php
│   │   │   └── ReportService.php
│   │   └── DTOs/
│   │       ├── CreateBookingData.php
│   │       ├── ApprovalDecisionData.php
│   │       ├── AvailabilityQueryData.php
│   │       └── AllotmentData.php
│   │
│   ├── Infrastructure/
│   │   ├── Queries/SqlAvailabilityQuery.php   # the locked overlap query
│   │   ├── Notifications/{EmailChannel,SmsChannel,InAppChannel}.php
│   │   ├── Pdf/DomPdfGenerator.php
│   │   └── Qr/BaconQrGenerator.php
│   │
│   ├── Models/
│   │   ├── User.php            Role.php          Permission.php
│   │   ├── BookingRequest.php  RequestOccupant.php   # guests + members
│   │   ├── RequestDocument.php                       # Aadhaar / ID proof
│   │   ├── Room.php            RoomType.php          # type is a TABLE
│   │   ├── Tariff.php                                # enables Revenue Report
│   │   ├── Allotment.php       StayExtension.php
│   │   ├── Feedback.php        AuditLog.php
│   │   ├── Notification.php    EmailTemplate.php
│   │   └── Holiday.php                               # holiday calendar
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/LoginController.php
│   │   │   ├── User/BookingRequestController.php
│   │   │   ├── Manager/ReviewController.php          # approve|reject|more-info
│   │   │   ├── Adg/ApprovalController.php            # approve|reject ONLY
│   │   │   ├── Admin/{AvailabilityController,AllotmentController}.php
│   │   │   ├── Admin/{RoomInventoryController,SettingsController}.php
│   │   │   ├── Shared/{StayController,FeedbackController,ReportController}.php
│   │   │   └── DashboardController.php
│   │   ├── Requests/            # validation per action
│   │   └── Middleware/EnsureRole.php
│   │
│   ├── Policies/{BookingRequestPolicy,AllotmentPolicy,RoomPolicy}.php
│   ├── Events/     RequestSubmitted · RequestResubmitted · ManagerApproved
│   │               RequestRejected · MoreInfoRequested · AdgApproved
│   │               RoomsAllotted · NoRoomAvailable · AvailabilityRecheckStarted
│   │               GuestCheckedIn · GuestCheckedOut · ExtensionRequested
│   │               ExtensionDecided · RequestCancelled
│   │               # authoritative list: WORKFLOW.md §4. Keys: NOTIFICATIONS.md §2.1
│   ├── Listeners/  SendNotifications · WriteAuditLog
│   └── Providers/{DomainServiceProvider,EventServiceProvider}.php
│
├── database/{migrations,seeders,factories}/
├── resources/views/            # Bootstrap Blade, per role
├── routes/{web.php,api.php}
├── storage/app/private/id-proofs/   # NEVER web-accessible
├── tests/
│   ├── Unit/      StateMachine · AvailabilityService · rooms_needed math
│   └── Feature/   full workflow per role + double-booking race test
└── docs/  README · PLAN · WORKFLOW · SCHEMA · ROUTES · SCREENS
           NOTIFICATIONS · REPORTS · SECURITY · TESTING
```

---

## 7. Security (absent in v1 — mandatory, Aadhaar-grade PII)

- ID proofs in `storage/app/private/`, served only through an authorized controller. Never a public URL.
- Aadhaar numbers encrypted at rest; masked in all UI and reports except to Admin.
- Every document view written to `audit_logs`.
- Rate limiting on login and forgot-password; strong password policy; session timeout; CSRF on all forms.
- Role checks enforced server-side in policies — never by hiding UI elements alone.


---

## 8. Build Phases (each phase ends in a working, verifiable slice)

Rule: no phase begins until the previous one's gate passes. This prevents big-bang breakage.

**Phase 0 — Foundation (0.5 day)**
Laravel install, MySQL connection, Bootstrap layout, git init.
*Gate:* app boots, `php artisan migrate` succeeds.

**Phase 1 — Identity & Roles (1.5 days)**
Users, roles, permissions tables. Login, logout, forgot-password. `EnsureRole` middleware. Seed one user per role.
*Gate:* each of the 4 roles logs in and is blocked from the other three areas. Feature test proves it.

**Phase 2 — Domain Core, no UI (1.5 days)**
`RequestStatus` enum + `RequestStateMachine` with the full transition table from §2.
*Gate:* unit tests cover every legal transition and reject every illegal one — including the More-Info return path and ADG having no More-Info option.

**Phase 3 — Booking Request (2.5 days)**
Request form: purpose, dates, members, occupants, host employee for Guest visits, ID-proof upload to private storage. Resubmit-after-More-Info.
*Gate:* user submits; request lands in `PENDING_MANAGER`; document is NOT reachable by public URL.

**Phase 4 — Approval Chain (2 days)**
Manager screen (Approve/Reject/More Info) and ADG screen (Approve/Reject only). Audit history panel as in screen 3.4.
*Gate:* full path User → Manager → ADG → `PENDING_ALLOTMENT` passes as a feature test; ADG more-info route returns 403.

**Phase 5 — Availability & Allotment (3 days — highest risk)**
Rooms, room_types, tariffs, `RoomStatus` incl. BLOCKED. Admin availability screen (3.3). Multi-room allotment with locking, `PARTIALLY_ALLOTTED`, `NO_ROOM_AVAILABLE`. Allotment letter as PDF.
*Gate:* the overlap rule is unit-tested at boundaries (checkout day reuse must pass), **and a concurrent double-booking test proves the lock holds.** No user-facing route exposes availability.

**Phase 6 — Stay Lifecycle (1.5 days)**
Check-in, check-out, early checkout, extension with re-verification. QR code on the allotment letter for check-in.
*Gate:* extension against an occupied room is correctly refused.

**Phase 7 — Notifications (1.5 days)**
Channel contract + Email/SMS/InApp. Wire all 19 event keys (NOTIFICATIONS.md §2 — the infographic's nine, expanded to cover every transition) via listeners. Email templates from DB.
*Gate:* each event produces the right notifications; tests use fake channels, zero real sends.

**Phase 8 — Dashboards & Reports (2 days)**
Admin dashboard tiles (Total, Pending-Manager, Pending-ADG, Allotted), occupancy chart, room-type pie. Seven reports from §6 with Excel/PDF export. Revenue uses tariffs.
*Gate:* seeded data reproduces correct counts; exports open cleanly.

**Phase 9 — Settings, Feedback, Hardening (1.5 days)**
Holiday calendar, email templates UI, feedback capture, security checklist from §7, Aadhaar masking.
*Gate:* security checklist fully ticked; full regression suite green.

**Total ≈ 18 working days ≈ 3.5–4 weeks** — consistent with the infographic's estimate, *because* v2 dropped the unnecessary repository layer. With v1's layering this would have overrun.

---

## 9. Locked Decisions Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Multiple rooms per request, from member count | User decision; one-to-many is painful to retrofit |
| 2 | ADG: Approve/Reject only | User decision — ADG's role is exactly what the image shows |
| 3 | Half-open date intervals `[in, out)` | Prevents losing one night of capacity per booking |
| 4 | Room types / tariffs / roles in DB, not enums | Open/Closed — admin extends without a deploy |
| 5 | No repository layer for CRUD | Avoids abstraction theatre; protects the timeline |
| 6 | Fully-approved-but-no-room → `NO_ROOM_AVAILABLE` | Infographic left this branch undefined |
| 7 | **Tailwind CSS 4 + Alpine.js instead of Bootstrap** | Deviation from the infographic's stack, approved by the client. Bootstrap 5 reads as a dated admin template; Tailwind is already the Laravel 12 default, so this removes a dependency rather than adding one. Alpine covers the conditional form logic (purpose branching, occupant rows) without SPA overhead. Blade stays server-rendered, which suits role-gated screens and an air-gapped network. |
| 8 | Roles seeded, not hardcoded, but mirrored by a `RoleSlug` enum | The four workflow roles are referenced in code without magic strings, while the `roles` table stays admin-editable |
| 9 | **Check-in requires `ALLOTTED`; `PARTIALLY_ALLOTTED` cannot check in** | Confirmed by the client. A request holding only some of its rooms must be completed before any guest is admitted, so a partly-housed party is never split or left without a bed. The Administration must either allot the remaining rooms or reduce `rooms_needed` first. |
| 10 | **Manager may select and hold rooms at review** | Client decision (2026-09-29), a deliberate exception to Core Rule 1. Narrow by construction: only the assigned Manager, only while `PENDING_MANAGER`, only that request's dates (`AvailabilityService::roomBoardForReview`, permission `room.allot.review`). Held rooms are real `ALLOTTED` rows created through the same four-layer lock as admin allotment, so two Managers cannot hold one room. ADG approval confirms them (T21 → `ALLOTTED` / `PARTIALLY_ALLOTTED`); ADG rejection or withdrawal releases them. Selecting rooms is optional; without it the Administration allots as before. Users still never see availability. |

### Frontend stack as built

| Layer | Choice |
|---|---|
| Templating | Blade (server-rendered) |
| CSS | Tailwind CSS 4 via `@tailwindcss/vite`, custom `@theme` token layer |
| JS | Alpine.js 3.15 |
| Build | Vite 7 |
| Fonts | Instrument Sans (UI) + Instrument Serif (institutional wordmark) |
| Charts | deferred to Phase 8 |

Design language is documented in `resources/css/app.css` under the heading "Institutional Calm": warm off-white canvas, hairline borders instead of heavy shadows, navy primary with emblem-gold used only as an active-state marker, and status always carried by text plus a dot rather than colour alone.

---

## 10. Open Questions (do not block Phases 0–4)

1. Does a Training-purpose booking auto-create requests for a whole batch, or does each trainee submit individually?
2. Is there a real payment step, or is the Revenue Report notional/internal accounting only?
3. Should VIP room types be restricted by officer rank/grade, or left entirely to admin discretion?

*(Item 4, on whether a `PARTIALLY_ALLOTTED` request may check in, was answered: no. It is now decision 9 above.)*
