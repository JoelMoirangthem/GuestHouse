# SCREENS.md — UI Specification

Each screen maps to the infographic. Bootstrap 5 + Blade. Field lists are exhaustive — do not add fields not listed, do not omit any.

Accessibility applies to all screens: every input has a `<label for>`, errors are announced via `aria-describedby` + `role="alert"`, focus order follows visual order, all interactive elements reachable by keyboard, colour is never the sole status indicator (pair badges with text), contrast ≥ 4.5:1.

---

## 3.1 Login (`/login`)

Header: national emblem, "NADT, RC, DTRTI · PRAGYA BHAWAN, LUCKNOW", subtitle "Guest House Booking System".

Fields: Username/Email (text, required, autofocus), Password (password, required, show/hide toggle), Remember me (checkbox), Login (submit).
Links: Forgot Password. "Register" → request-access form, **not** self-service signup (ROUTES.md).

Behaviour: generic error "Invalid credentials" — never reveal which field was wrong. Throttle message after 5 attempts. On success redirect by role: user→`/my/requests`, manager→`/manager/requests`, adg→`/adg/requests`, admin→`/dashboard`.

---

## 3.2 Booking Request Form (`/my/requests/create`)

Sections in this order:

**Applicant** — Full Name (prefilled, readonly), Employee Code (readonly), Mobile (required, 10 digits), Email (required).

**Purpose of Visit** — radio group, required, exactly one:
- `Training Purpose` — "I am coming for Training Program" → reveals **Training Program Name** (required)
- `Self Visit` — "I am visiting on Official Work"
- `Guest Visit` — "I am a Guest of NADT Employee" → reveals **Host Employee** (searchable select of users, required) and **Guest Of (name)** free-text fallback

Conditional fields are `required` only when their branch is active, enforced server-side in the FormRequest, not just by JS.

**Stay Details** — From Date (date, ≥ today), To Date (date, > From), Number of Days (readonly, computed), Total Members (number, min 1), Preferred Room Type (select, optional, labelled **"Preference only — allotment is decided by the Administration"**).

**Occupants** — repeatable rows, count must equal Total Members: Name (required), Age, Gender, Relation, ID Proof Type, ID Proof Number (masked input).

**Documents** — ID Proof upload (pdf/jpg/png, max 5 MB, required for the primary occupant).

**Remarks** — textarea, optional.

Actions: Save as Draft, Submit Request.

**Critical:** this screen shows **no availability information whatsoever** — no room counts, no calendar of free rooms, no "rooms left" hint. Core Rule 2. A visible availability widget here would be a requirements violation, not a feature.

---

## 3.3 Admin — Check Availability (`/admin/requests/{id}/availability`)

Reachable **only** when request status is `PENDING_ALLOTMENT` or `PARTIALLY_ALLOTTED`; otherwise 409 with an explanatory page. Admin role only.

Top: request summary (request_no, requester, purpose, dates, members, `rooms_needed`).

Search: From Date, To Date (prefilled from the request, editable), Search button.

Results — "Availability Summary (Admin View)", grouped by category:

| Category | Room Type | Total | Available |
|---|---|---|---|
| VIP Rooms | Deluxe AC (VIP) | 10 | 4 |
| | Suite AC (VIP) | 6 | 2 |
| Normal Rooms | Standard AC | 30 | 9 |
| | Non-AC | 15 | 6 |

Footer note, verbatim intent from the infographic: **"Availability checked by Admin only."**

Actions: Proceed to Allotment, or Mark as No Room Available (requires confirmation + reason).

Stamps `availability_checked_at` / `availability_checked_by` on first search — this is the audit proof that Core Rule 1 was honoured.

---

## 3.4 Manager Review (`/manager/requests/{id}`)

**Request Details** panel: Request ID, Name, Purpose, From, To, Persons, Status ("Pending at Manager"), Remarks, attached documents (gated links).

**Action** panel: `APPROVE` (green), `REJECT` (red — remarks required), `MORE INFO` (amber — note required). Each behind a confirmation dialog.

**History** panel: reverse-chronological `audit_logs` for this request — timestamp, actor, role, action, from→to status, remarks. Read-only.

ADG's screen (`/adg/requests/{id}`) is identical **except the MORE INFO button does not render, and no such route exists.** Decision 5.

---

## 3.5 Room Allotment (`/admin/requests/{id}/allot`)

**Allotment Details**: Request ID, Name, Purpose, `rooms_needed` (suggested), From, To.

**Room selection**: multi-select list of currently-available rooms for the range, showing room number, type, capacity. Admin may select fewer or more than `rooms_needed` — the count is a suggestion, since allotment is at the authority's discretion. Per selected room, set `occupants_count` (≤ capacity).

Read-only computed: rate per night (from `tariffs`), nights, total amount.

Confirm → runs the locked transaction (SCHEMA.md §14 Step 2). On success: green banner "Room successfully allotted", status becomes `ALLOTTED` or `PARTIALLY_ALLOTTED`.

If the re-verification inside the lock finds a room was just taken, show "Room 101 was allotted by another admin moments ago — please re-check availability" and return to 3.3. This is the user-visible face of the concurrency guard and must be tested.

Actions: Print / PDF (allotment letter with QR).

---

## 4. Dashboard (`/dashboard`)

Admin sees five tiles (REPORTS.md §1 — including "Awaiting Availability Check"), occupancy chart, room-type pie.
Manager sees pending-with-me count and team stats. ADG sees pending-with-ADG count. User sees own requests with status badges.

Status badges use text plus colour (never colour alone), covering every non-`DRAFT` state:

| State | Badge text | Colour |
|---|---|---|
| `PENDING_MANAGER` | Pending at Manager | amber |
| `MORE_INFO_MANAGER` | Information Required | orange |
| `REJECTED_MANAGER` | Rejected by Manager | red |
| `PENDING_ADG` | Pending at ADG | blue |
| `REJECTED_ADG` | Rejected by ADG | red |
| `PENDING_ALLOTMENT` | Awaiting Allotment | teal |
| `NO_ROOM_AVAILABLE` | No Room Available | dark red |
| `PARTIALLY_ALLOTTED` | Partially Allotted | light green |
| `ALLOTTED` | Allotted | green |
| `CHECKED_IN` | Staying | dark green |
| `EXTENSION_REQUESTED` | Extension Requested | purple |
| `CHECKED_OUT` | Completed | grey |
| `EARLY_CHECKOUT` | Completed (Early) | grey |
| `CANCELLED` | Cancelled | dark grey |

---

## 5. Notifications Bell

Header dropdown: unread count, latest 10 in-app notifications, mark-as-read, link to full list. Own notifications only.

---

## 6. Room Inventory (`/admin/inventory`)

Columns: Category, Room Type, Total, Available, Booked, Blocked (REPORTS.md §4). Date-range picker for Admin.
Footer note: "Availability is for management view only. Room allotment is at the discretion of the authority."
Manager/ADG see the same table without the date-range picker.

---

## 7. Shared UI Rules

- Mobile-first; tables become stacked cards below 768px.
- Every destructive or irreversible action (reject, cancel, no-room, release room) requires a confirm dialog naming the specific record.
- Submit buttons disable on click to prevent duplicate POSTs — a real double-booking vector.
- Dates displayed `DD/MM/YYYY` (Indian convention), stored ISO.
- Currency as `₹ 1,234.00` with Indian digit grouping.
