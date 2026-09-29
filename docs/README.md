# Guest House Booking & Approval System

Guest house room booking, multi-level approval, allotment and reporting for
**Pragya Bhawan · NADT, RC, DTRTI · Lucknow, Uttar Pradesh — 226002**

Stack: Laravel (PHP) · MySQL 8 · Tailwind CSS 4 + Alpine.js (PLAN.md decision 7) · SMTP · QR · PDF

---

## The three rules that define this system

1. **Availability is checked by the ADMIN only, and only after Manager + ADG approval.** Sole exception: the reviewing Manager may hold rooms for the request in front of them (PLAN.md decision 10).
2. **Users never see availability or room inventory.** They declare intent only.
3. **Approval decides entitlement. Allotment decides which rooms.** Different roles, different stages.

If a change would violate any of these, it is wrong — even if it looks like a usability improvement.

---

## Documentation index — read in this order

| Doc | Contains | Read when |
|---|---|---|
| **[PLAN.md](PLAN.md)** | Architecture, folder structure, SOLID rationale, 10 build phases, locked decisions | First. Always. |
| **[WORKFLOW.md](WORKFLOW.md)** | All 15 states, all 20 transitions, forbidden transitions, role×action matrix, side effects | Touching status logic |
| **[SCHEMA.md](SCHEMA.md)** | Every table, column, index, plus the exact availability SQL and concurrency guard | Writing migrations or queries |
| **[ROUTES.md](ROUTES.md)** | Every route, controller, middleware, permission slug | Adding an endpoint |
| **[SCREENS.md](SCREENS.md)** | Field-level UI spec per infographic screen, accessibility rules | Building Blade views |
| **[NOTIFICATIONS.md](NOTIFICATIONS.md)** | 19 events → channels → recipients, event-class→key mapping, template placeholders | Wiring notifications |
| **[REPORTS.md](REPORTS.md)** | Exact formula for every dashboard tile and the 7 reports | Building dashboards/reports |
| **[SECURITY.md](SECURITY.md)** | Threat model, Aadhaar/PII handling, auth hardening, sign-off checklist | Before any PII or auth work, and at Phase 9 |
| **[TESTING.md](TESTING.md)** | Required unit + feature tests, phase gates, seed data | Every phase |

---

## Anti-hallucination contract

These documents are the specification. When implementing:

- **Do not invent** tables, columns, routes, states, permissions or events that are not in these docs.
- **Do not add** a field to a screen that SCREENS.md does not list.
- **Do not create** a status transition absent from WORKFLOW.md §2 — the state machine must throw on anything unlisted.
- **Do not expose** availability to any role other than Admin, in any form, anywhere.
- If a requirement seems missing, check PLAN.md §10 (Open Questions) first. If it is genuinely unspecified, **ask rather than assume** — then record the answer in PLAN.md §9 (Decisions Log).
- If a doc contradicts another, PLAN.md wins, and the contradiction must be fixed rather than worked around.

Every non-obvious design choice already carries its rationale inline, so the *why* survives handover.

---

## Locked decisions (PLAN.md §9)

1. **Multiple rooms per request**, derived from member count — request→allotments is one-to-many
2. **ADG can only Approve or Reject** — no More Info; enforced by absent route + absent permission + absent transition
3. **Half-open date intervals `[check_in, check_out)`** — checkout day is immediately reusable
4. **Room types, tariffs, roles live in DB tables, not enums** — admins extend without a deploy
5. **No repository layer for plain CRUD** — Eloquent is already the abstraction; abstractions kept only for notifications, PDF, QR and the availability query
6. **Approved but no room → `NO_ROOM_AVAILABLE`** — a branch the source infographic left undefined

---

## Two risks that deserve standing attention

**Double-booking.** Because availability is resolved late by design, concurrent allotment is the system's sharpest edge. Four layers defend it (transaction, row lock, re-verify inside the lock, unique index) — see SCHEMA.md §14. `DoubleBookingRaceTest` is not optional.

**Aadhaar exposure.** ID proofs and Aadhaar numbers are the most sensitive data here. Private storage, encryption at rest, masking everywhere including exports, and never in email or SMS — see SECURITY.md §2.

---

## Build order

Phases 0→9 in PLAN.md §8, ≈18 working days. Each phase ends in a working, tested slice; no phase starts before the previous gate passes (TESTING.md §3).

Phase 5 (availability and allotment) is the highest-risk phase and is budgeted 3 days accordingly.
