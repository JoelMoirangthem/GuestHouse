# REPORTS.md — Dashboards & Reports Specification

Every metric defined unambiguously, so two developers compute the same number. Derived from infographic §4 and §6.

---

## 1. Admin Dashboard Tiles (infographic §4)

| Tile | Sample | Definition |
|---|---|---|
| Total Requests | 125 | `COUNT(*)` of `booking_requests` in the selected period, excluding `DRAFT` and soft-deleted |
| Pending (Manager) | 18 | status IN (`PENDING_MANAGER`, `MORE_INFO_MANAGER`) |
| Pending (ADG) | 07 | status = `PENDING_ADG` |
| Rooms Allotted | 96 | `COUNT(*)` of `allotments` with status IN (`ALLOTTED`, `CHECKED_IN`, `CHECKED_OUT`, `EARLY_CHECKOUT`) — **rooms, not requests** |

"Rooms Allotted" counts allotment rows, which is why it can exceed the request count once Decision 1 (multi-room) is live. Do not conflate the two.

Add a fifth tile not in the infographic but required by the workflow: **Awaiting Availability Check** = status IN (`PENDING_ALLOTMENT`, `PARTIALLY_ALLOTTED`). This is the admin's actual work queue; without it the admin has no way to find approved requests needing action.

## 2. Dashboard Charts

**Occupancy Overview (this month)** — line/bar by day:
```
occupancy_pct(day) = occupied_room_nights(day) / total_active_rooms × 100

total_active_rooms = COUNT(rooms WHERE status = 'ACTIVE')

occupied_room_nights(day) = COUNT(allotments WHERE status IN ('ALLOTTED','CHECKED_IN','CHECKED_OUT','EARLY_CHECKOUT')
                                   AND check_in_date <= day AND day < check_out_date)
```
`total_active_rooms` counts only `status='ACTIVE'` rooms and deliberately **ignores** date-ranged blocks, so the denominator is stable across a month and the chart is comparable day to day. A date-blocked room therefore lowers occupancy rather than shrinking the base — the intended reading.

Note `day < check_out_date` — consistent with half-open intervals. A guest checking out on the 22nd did not occupy the room on the night of the 22nd.

**Room Type Wise (pie)** — allotment count grouped by `room_types.name` for the period.

---

## 3. The Seven Reports (infographic §6)

Each supports a date range and exports to XLSX and PDF.

**1. Occupancy Report** — per room per day, or aggregated per room type. Columns: room, type, nights occupied, nights available, occupancy %. Uses the formula above.

**2. Booking Report** — every request. Columns: request_no, requester, purpose, dates, nights, members, status, manager decision + date, ADG decision + date, rooms allotted. This is the master audit-facing report.

**3. Allotment Report** — one row per allotment. Columns: allotment_no, request_no, guest, room, type, check-in/out dates, actual in/out, status, amount.

**4. User-wise Report** — grouped by requester. Columns: user, department, requests submitted, approved, rejected, room-nights consumed, total amount.
```
room_nights(user) = SUM(DATEDIFF(a.check_out_date, a.check_in_date))
                    over allotments a of that user's requests
                    WHERE a.status IN ('CHECKED_OUT','EARLY_CHECKOUT','CHECKED_IN','ALLOTTED')
```
One allotment = one room, so a 3-room 2-night stay is 6 room-nights. `CANCELLED` allotments are excluded.

**5. Purpose-wise Report** — grouped by `purpose` (Training / Self / Guest). Columns: purpose, request count, approval rate, avg nights, room-nights, revenue. Directly serves the infographic's "Purpose Wise Report (Training / Self / Guest)".

**6. Revenue Report** — admin only.
```
revenue = SUM(allotments.total_amount) WHERE status IN ('CHECKED_OUT','EARLY_CHECKOUT')
```
Two figures must be reported separately and never summed:
- **Realised** — statuses above (stay completed)
- **Projected** — status IN (`ALLOTTED`,`CHECKED_IN`)

`rate_per_night` is the snapshot on the allotment row, not the current tariff. A tariff revision must never retroactively change last quarter's revenue.

For `EARLY_CHECKOUT`, `total_amount` is recomputed on nights actually stayed:
```
nights_stayed = GREATEST(1, DATEDIFF(DATE(actual_check_out_at), DATE(actual_check_in_at)))
total_amount  = rate_per_night × nights_stayed
```
Anchor on `actual_check_in_at`, **not** the scheduled `check_in_date` — a guest who arrived a day late and left early must not be billed for the absent night. Both `actual_*` columns are DATETIME, so wrap each in `DATE()` before `DATEDIFF` to avoid a partial-day result. Minimum one night is always charged.

**7. Feedback Report** — avg ratings (cleanliness, staff, facilities, overall), response rate = feedback rows ÷ completed stays, plus comment list.

---

## 4. Room Inventory View (infographic §7)

| Column | Definition |
|---|---|
| Total Rooms | `COUNT(rooms)` of that type |
| Available | passes the SCHEMA.md §14 availability predicate for the chosen range |
| Booked | has an overlapping allotment in `ALLOTTED` or `CHECKED_IN` |
| Blocked | `status IN ('BLOCKED','MAINTENANCE')` **or** a `blocked_from/blocked_to` range overlapping the query window |

Invariant that must hold and should be asserted in tests: `Available + Booked + Blocked = Total` for any given date range. A room cannot be counted twice.

Access: Admin gets the full view with a date range. Manager and ADG get **current-state counts only, with no date-range search** — per the infographic note that this is "for management view only", while date-range availability remains admin-exclusive under Core Rule 1.

---

## 4a. Implementation decisions (Phase 8)

Recorded here because the sections above left them open. `ReportService` is the single implementation.

**Period anchoring.** A request belongs to the period of its `submitted_at`. Occupancy and room-nights count only nights inside the period. An allotment row is listed if its stay overlaps the period. Revenue is attributed to the checkout date (actual for realised, scheduled for projected), so a stay spanning two months is counted once. The queue tiles (Pending Manager, Pending ADG, Awaiting Availability) are current counts, not period counts.

**Early-checkout correction to section 2.** The literal `day < check_out_date` test uses the *scheduled* date, which `EARLY_CHECKOUT` rows keep. The room has been released and may already be re-allotted, so the literal formula counts one room twice and can exceed 100%. For `EARLY_CHECKOUT` the stay ends on `DATE(actual_check_out_at)`, never earlier than one night — the same minimum the guest is billed for.

**Approval rate.** approved ÷ (approved + rejected). "Approved" = cleared the ADG. Undecided and withdrawn requests are excluded from both sides.

**Large exports.** Section 5 asks for exports over 5,000 rows to be queued. That is not built yet; such an export is refused with a request to narrow the range, rather than truncated.

**Spreadsheet safety.** Every string is written to XLSX as literal text, so free text such as a feedback comment starting with `=` can never become a live formula.
## 5. Export Rules

- XLSX via `maatwebsite/excel`; PDF via the `PdfGenerator` contract.
- Every export header carries: report name, date range, generated-by, generated-at, and the institution name.
- **Aadhaar and ID-proof numbers are masked in every export**, without exception (SECURITY.md §2).
- Exports over 5,000 rows are queued and delivered as a notification rather than generated in-request.
- Report queries are read-only and must not be wrapped in write transactions.

---

## 6. Scope by Role

| Report | User | Manager | ADG | Admin |
|---|---|---|---|---|
| Own booking history | ✅ | ✅ | ✅ | ✅ |
| Occupancy | — | 👁 | 👁 | ✅ |
| Booking | — | 👁 team | 👁 all | ✅ |
| Allotment | — | 👁 team | 👁 all | ✅ |
| User-wise | — | 👁 team | 👁 all | ✅ |
| Purpose-wise | — | 👁 | 👁 | ✅ |
| **Revenue** | — | — | — | ✅ only |
| Feedback | — | 👁 | 👁 | ✅ |

"team" = users whose `reporting_manager_id` is the acting manager.
