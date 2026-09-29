# TESTING.md — Test Plan & Phase Gates

No phase is complete until its gate tests pass. PHPUnit (Laravel default), MySQL test database, `RefreshDatabase`.

---

## 1. Unit Tests — `tests/Unit`

### `RequestStateMachineTest` (highest value in the suite)
- Every one of the 20 transitions in WORKFLOW.md §2 succeeds under valid guards. **20 tests minimum.**
- Every transition fails when the actor role is wrong.
- Each forbidden transition from WORKFLOW.md §2 throws `InvalidTransitionException`:
  - `PENDING_ADG` → `MORE_INFO_MANAGER` (ADG has no more-info)
  - `PENDING_MANAGER` → `PENDING_ALLOTMENT` (skips ADG)
  - `PENDING_ADG` → `ALLOTTED` (skips availability)
  - any transition out of each of the 6 terminal states
- `MORE_INFO_MANAGER` → `PENDING_MANAGER` (T5) succeeds — the return path v1 omitted.

### `OverlapPredicateTest`
The six boundary rows from SCHEMA.md §14 **verbatim**:

| Existing | New | Expected |
|---|---|---|
| 20→22 | 22→24 | no overlap |
| 20→22 | 21→23 | overlap |
| 20→22 | 19→21 | overlap |
| 20→22 | 20→22 | overlap |
| 20→22 | 18→20 | no overlap |
| 20→26 | 21→23 | overlap |

The first and fifth rows are the ones that catch a closed-interval implementation. If they fail, one night of capacity per booking is being silently lost.

### `RoomsNeededTest`
`ceil(members / capacity)`: (1,2)→1, (2,2)→1, (3,2)→2, (4,2)→2, (5,2)→3, (1,1)→1, (7,3)→3. Room-level `capacity` override takes precedence over `room_types.default_capacity`.

### `TariffResolutionTest`
Purpose-specific tariff beats the NULL-purpose one; latest `effective_from` covering the date wins; expired tariffs (`effective_to` past) are ignored.

### `AvailabilityServiceTest`
- Throws when the actor is not admin.
- Throws when request status ≠ `PENDING_ALLOTMENT` / `PARTIALLY_ALLOTTED`.
- Excludes `BLOCKED` and `MAINTENANCE` rooms.
- Excludes rooms whose `blocked_from/blocked_to` overlaps the window.
- Ignores `CANCELLED`, `CHECKED_OUT`, `EARLY_CHECKOUT` allotments when computing availability.

### `NotificationDispatcherTest`
Each of the 19 `event_key`s resolves to the correct channels and recipients per NOTIFICATIONS.md §2, using fake channels. Asserts no real send. Asserts no template output contains an Aadhaar number. Asserts every key in the §2.1 mapping has an `email_templates` row, and that `stay.extension_requested` reaches an **Admin** (regression: this event was missing in an early draft, leaving extension requests un-actioned).

### `AvailabilitySqlRegressionTest`
Two defects found in specification audit; both must stay fixed:
- **Open-ended block:** a room with `blocked_from` set and `blocked_to = NULL` must count as unavailable. The naive predicate yields NULL and `NOT(NULL)` is not true, so the room wrongly appeared available.
- **Candidate selection:** with 10 rooms of a type where the 3 lowest-numbered are occupied and `rooms_needed = 2`, the query must return 2 free higher-numbered rooms — **not** zero. Filtering by `status` alone before `LIMIT ... FOR UPDATE` locks occupied rooms and spuriously reports no availability to a fully approved applicant.

---

## 2. Feature Tests — `tests/Feature`

### `HappyPathWorkflowTest`
Full journey in one test: user submits → manager approves → ADG approves → admin checks availability → admin allots 2 rooms for 4 members → check-in → check-out → feedback. Asserts status at each step and that `audit_logs` has one row per transition.

### `MoreInfoLoopTest`
Submit → manager requests more info → user resubmits → manager approves. Asserts `more_info_at` and `resubmitted_at` are set and the request returns to `PENDING_MANAGER`.

### `AdgCannotRequestMoreInfoTest`
Authenticated as ADG, POST to a manager more-info URL → 403/404. Also assert no route named for ADG more-info exists in the route collection. Decision 5, three layers.

### `AvailabilityIsAdminOnlyTest`
For each of user, manager, ADG: GET and POST the availability endpoints → 403. Then assert the whole registered route list contains **no** availability route under `/my/`. Core Rule 2.

### `AvailabilityBlockedBeforeApprovalTest`
Admin attempts the availability check while status is `PENDING_MANAGER` and `PENDING_ADG` → 409 both times. Core Rule 1.

### `DoubleBookingRaceTest` — the most important feature test
Two concurrent allotment attempts on the same room and overlapping dates. Exactly one must succeed; the other must fail cleanly with the "allotted by another admin" path, **not** a 500. Implementation: two DB connections, deliberately interleaved transactions; or a `pcntl`/parallel-process harness. If true concurrency cannot be simulated in the environment, fall back to asserting the unique index on `(room_id, occupies)` rejects the duplicate insert — and document the reduced coverage explicitly rather than silently skipping.

**As built:** real concurrency, no fallback. Each attempt is a separate PHP process (`tests/Support/race_allot.php`) with its own MySQL connection, released together from a file barrier. Four tests: four admins with overlapping but different start dates (only the lock + re-check can stop these; the unique index cannot); identical dates; a deterministic interleave where the test holds the row lock and commits a competing booking while the child waits; and two different rooms at once, which must both succeed. Removing the post-lock re-check makes two of them fail. The class commits real rows, so it truncates every table before and after itself.

### `MultiRoomAllotmentTest`
6 members, capacity 2 → `rooms_needed` = 3. Allot 2 → status `PARTIALLY_ALLOTTED`. Allot the 3rd → `ALLOTTED`. Release one → back to `PARTIALLY_ALLOTTED`.

### `NoRoomAvailableTest`
Approve a request when all rooms are booked → admin marks `NO_ROOM_AVAILABLE` → user and ADG notified. Then T20 re-check moves it back to `PENDING_ALLOTMENT`.

### `ExtensionTest`
Extension approved when nights are free; **denied** when the same room is booked by someone else for the extra nights. Asserts `check_out_date` and `total_amount` update only on approval.

### `RoleIsolationMatrixTest`
Data-provider driven: for all 4 roles × every privileged route of the other 3 roles, assert 403/404. This single test is the backbone of the authorization guarantee.

### `DocumentAccessTest`
- Owner can download own ID proof.
- Another regular user gets 403.
- Every successful download writes an `audit_logs` row.
- The raw `storage/app/private/...` path is not reachable over HTTP.

### `PiiMaskingTest`
Aadhaar appears masked (`XXXX XXXX 1234`) in the request view, the allotment PDF, and the XLSX export. Full value appears nowhere in any response body.

### `CapacityTest`
Allotment with `occupants_count` above effective room capacity is rejected.

### `DateValidationTest`
`check_out_date` ≤ `check_in_date` rejected; past `check_in_date` rejected; occupant row count ≠ `total_members` rejected.

---

## 3. Phase Gate Summary

| Phase | Gate |
|---|---|
| 0 | App boots; migrations run |
| 1 | Login works for 4 roles; `RoleIsolationMatrixTest` green |
| 2 | `RequestStateMachineTest` fully green (all 20 + all forbidden) |
| 3 | Request submits; `DocumentAccessTest`, `DateValidationTest` green |
| 4 | `HappyPathWorkflowTest` reaches `PENDING_ALLOTMENT`; `MoreInfoLoopTest`, `AdgCannotRequestMoreInfoTest` green |
| 5 | `OverlapPredicateTest`, `AvailabilitySqlRegressionTest`, `DoubleBookingRaceTest`, `MultiRoomAllotmentTest`, `NoRoomAvailableTest`, `AvailabilityIsAdminOnlyTest`, `AvailabilityBlockedBeforeApprovalTest` green |
| 6 | `ExtensionTest`, `CapacityTest` green |
| 7 | `NotificationDispatcherTest` green; zero real sends |
| 8 | Dashboard tiles match seeded data; `Available + Booked + Blocked = Total` asserted |
| 9 | `PiiMaskingTest` green; SECURITY.md §7 checklist fully ticked; full suite green |

---

## 4. Seed Data for Testing

`DatabaseSeeder`: 4 roles + permission map; one user per role plus 3 regular users with `reporting_manager_id` set; 4 room types (Deluxe AC VIP cap 2, Suite AC VIP cap 3, Standard AC cap 2, Non-AC cap 2); rooms matching infographic §7 counts (10, 6, 30, 15) with 2 blocked — **one permanently blocked and one with `blocked_to = NULL`**, so the open-ended-block regression is always exercised; current tariffs per type; 19 email templates (one per key in NOTIFICATIONS.md §2.1); the year's holidays.

Room counts deliberately mirror the infographic so dashboard figures can be eyeballed against the source during review.
