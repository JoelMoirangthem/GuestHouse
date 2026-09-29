# WORKFLOW.md — State Machine Specification

The single authority for what transitions exist. `RequestStateMachine` implements this table literally. Any transition not listed here MUST throw `InvalidTransitionException`.

---

## 1. States (`RequestStatus` enum)

| Value | Meaning | Terminal |
|---|---|---|
| `DRAFT` | Saved, not submitted | no |
| `PENDING_MANAGER` | Awaiting Manager | no |
| `MORE_INFO_MANAGER` | Manager asked user for information | no |
| `REJECTED_MANAGER` | Manager rejected | **yes** |
| `PENDING_ADG` | Manager approved, awaiting ADG | no |
| `REJECTED_ADG` | ADG rejected | **yes** |
| `PENDING_ALLOTMENT` | Fully approved — **admin may now check availability** | no |
| `NO_ROOM_AVAILABLE` | Approved but nothing free | **yes** |
| `PARTIALLY_ALLOTTED` | Some of `rooms_needed` assigned | no |
| `ALLOTTED` | All required rooms assigned | no |
| `CHECKED_IN` | Guest(s) in residence | no |
| `CHECKED_OUT` | Completed normally | **yes** |
| `EARLY_CHECKOUT` | Left before `check_out_date` | **yes** |
| `CANCELLED` | Cancelled before check-in | **yes** |
| `EXTENSION_REQUESTED` | Extension pending admin decision | no |

`PENDING_ALLOTMENT` is the gate for Core Rule 1. `AvailabilityService` refuses to run in any other state.

---

## 2. Transition Table

| # | From | To | Actor | Guards |
|---|---|---|---|---|
| T1 | `DRAFT` | `PENDING_MANAGER` | User (owner) | dates valid; occupants count = `total_members`; ID proof present; purpose fields complete; requester has a `reporting_manager_id` |
| T2 | `PENDING_MANAGER` | `PENDING_ADG` | Manager | actor is the requester's reporting manager (or has override) |
| T3 | `PENDING_MANAGER` | `REJECTED_MANAGER` | Manager | remarks required |
| T4 | `PENDING_MANAGER` | `MORE_INFO_MANAGER` | Manager | `more_info_note` required |
| T5 | `MORE_INFO_MANAGER` | `PENDING_MANAGER` | User (owner) | user supplied the requested info; sets `resubmitted_at` |
| T6 | `PENDING_ADG` | `PENDING_ALLOTMENT` | **ADG** | — |
| T7 | `PENDING_ADG` | `REJECTED_ADG` | **ADG** | remarks required |
| T8 | `PENDING_ALLOTMENT` | `PARTIALLY_ALLOTTED` | Admin | 0 < rooms allotted < `rooms_needed` |
| T9 | `PENDING_ALLOTMENT` | `ALLOTTED` | Admin | rooms allotted ≥ `rooms_needed` |
| T10 | `PENDING_ALLOTMENT` | `NO_ROOM_AVAILABLE` | Admin | availability query returned zero rooms |
| T11 | `PARTIALLY_ALLOTTED` | `ALLOTTED` | Admin | remaining rooms now assigned |
| T12 | `ALLOTTED` | `CHECKED_IN` | Admin | today ≥ `check_in_date`; ≥1 allotment active |
| T13 | `ALLOTTED` | `CANCELLED` | User (owner) or Admin | not yet checked in; releases all rooms |
| T14 | `CHECKED_IN` | `CHECKED_OUT` | Admin | today ≥ `check_out_date` |
| T15 | `CHECKED_IN` | `EARLY_CHECKOUT` | Admin | today < `check_out_date`; releases rooms immediately |
| T16 | `CHECKED_IN` | `EXTENSION_REQUESTED` | User (owner) | requested date > current `check_out_date` |
| T17 | `EXTENSION_REQUESTED` | `CHECKED_IN` | Admin | **approve** — extra nights available on same rooms; updates `check_out_date` + `total_amount` |
| T18 | `EXTENSION_REQUESTED` | `CHECKED_IN` | Admin | **deny** — original dates stand; `denial_reason` required |
| T19 | `PENDING_MANAGER` / `MORE_INFO_MANAGER` / `PENDING_ADG` | `CANCELLED` | User (owner) | withdraw before approval completes |
| T20 | `NO_ROOM_AVAILABLE` | `PENDING_ALLOTMENT` | Admin | re-check later (waitlist retry) |
| T21 | `PENDING_ALLOTMENT` | `ALLOTTED` / `PARTIALLY_ALLOTTED` | ADG | applied inside the ADG approval, immediately after T6, only when the Manager held rooms (PLAN.md decision 10) |

T17 and T18 share a target state but are distinct actions with different side effects and different audit entries.

### Explicitly forbidden — must be tested as rejections
- `PENDING_ADG` → `MORE_INFO_*` by anyone. **The ADG has no More Info action** (Decision 5). Route returns 403.
- `PENDING_MANAGER` → `PENDING_ALLOTMENT` (skipping ADG).
- `PENDING_ADG` → `ALLOTTED` (skipping the availability step).
- Any availability or allotment action while status ≠ `PENDING_ALLOTMENT` / `PARTIALLY_ALLOTTED`.
- Any transition out of a terminal state.
- Any user-role access to availability data, in any state.

---

## 3. Role × Action Matrix

| Action | User | Manager | ADG | Admin |
|---|---|---|---|---|
| Create / submit request | ✅ own | — | — | — |
| Respond to More Info | ✅ own | — | — | — |
| Cancel before check-in | ✅ own | — | — | ✅ |
| Approve / Reject (stage 1) | — | ✅ | — | — |
| **Request More Info** | — | ✅ | ❌ | — |
| Approve / Reject (stage 2) | — | — | ✅ | — |
| **Check availability** | ❌ | ❌ | ❌ | ✅ |
| Allot / reallocate rooms | — | — | — | ✅ |
| Block / unblock a room | — | — | — | ✅ |
| Check-in / Check-out | — | — | — | ✅ |
| Request extension | ✅ own | — | — | — |
| Decide extension | — | — | — | ✅ |
| Submit feedback | ✅ own | — | — | — |
| View room inventory | ❌ | 👁 read | 👁 read | ✅ |
| Reports | 👁 own | 👁 team | 👁 all | ✅ |

❌ = hard denial, enforced in policy. 👁 = read-only.
Manager/ADG get read-only inventory per the infographic §7 note ("for management view only"), but **availability-for-a-date-range stays admin-exclusive.**

---

## 4. Side Effects per Transition

| Transition | Audit action | Events fired | Notified |
|---|---|---|---|
| T1 | `SUBMITTED` | `RequestSubmitted` | Manager, User |
| T2 | `MANAGER_APPROVED` | `ManagerApproved` | ADG, User |
| T3 | `MANAGER_REJECTED` | `RequestRejected` | User |
| T4 | `MORE_INFO_REQUESTED` | `MoreInfoRequested` | User |
| T5 | `RESUBMITTED` | `RequestResubmitted` | Manager |
| T6 | `ADG_APPROVED` | `AdgApproved` | Admin, User |
| T7 | `ADG_REJECTED` | `RequestRejected` | User, Manager |
| T8/T9/T11 | `ROOMS_ALLOTTED` | `RoomsAllotted` | User |
| T10 | `NO_ROOM_AVAILABLE` | `NoRoomAvailable` | User, ADG |
| T12 | `CHECKED_IN` | `GuestCheckedIn` | User |
| T14/T15 | `CHECKED_OUT` / `EARLY_CHECKOUT` | `GuestCheckedOut` | User |
| T16 | `EXTENSION_REQUESTED` | `ExtensionRequested` | Admin |
| T17/T18 | `EXTENSION_APPROVED` / `EXTENSION_DENIED` | `ExtensionDecided` | User |
| T13/T19 | `CANCELLED` | `RequestCancelled` | Requester, Admin, Manager |
| T20 | `AVAILABILITY_RECHECK` | `AvailabilityRecheckStarted` | Requester |

Every row writes exactly one `audit_logs` entry with `from_status` and `to_status`. Notifications are dispatched by listeners, never inline in a service, so a mail failure can never roll back an approval.

---

## 5. Transaction Boundaries

- Each transition runs in **one** DB transaction: guard check → status update → audit write → commit.
- Events dispatch **after commit** (`DB::afterCommit`), so no notification is ever sent for a rolled-back approval.
- Allotment (T8/T9/T11) additionally holds `FOR UPDATE` locks on candidate rooms per SCHEMA.md §14 and re-verifies overlap *inside* the lock.
