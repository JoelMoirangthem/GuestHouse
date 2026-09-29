# SCHEMA.md — Database Design (MySQL 8)

Authoritative table definitions. Derived from PLAN.md v2. Do not invent columns not listed here.

Conventions: `id` = BIGINT UNSIGNED AUTO_INCREMENT PK. All tables carry `created_at`/`updated_at` unless noted. Money = `DECIMAL(10,2)`. Dates that represent a stay are **DATE**, not DATETIME (nights, not hours). Deletions are soft where marked.

---

## 1. `roles`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| slug | VARCHAR(30) UNIQUE | `user` · `manager` · `adg` · `admin` |
| name | VARCHAR(60) | display |
| is_active | BOOLEAN | default 1 |

Table-backed, not an enum — Module 1 requires managing roles. Seeded with exactly the four roles.

## 2. `permissions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| slug | VARCHAR(60) UNIQUE | e.g. `request.approve.manager`, `availability.check`, `room.allot` |
| name | VARCHAR(100) | |

## 3. `role_permission`
| Column | Type | Notes |
|---|---|---|
| role_id | FK roles | ON DELETE CASCADE |
| permission_id | FK permissions | ON DELETE CASCADE |

PK = (role_id, permission_id).

**Critical seed:** `request.approve.adg` grants approve+reject only. There is **no** `request.moreinfo.adg` permission — this is how Decision 5 is enforced structurally.

## 4. `users`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| employee_code | VARCHAR(30) UNIQUE NULL | NULL for non-employee guests |
| name | VARCHAR(120) | |
| email | VARCHAR(150) UNIQUE | login id |
| mobile | VARCHAR(15) | |
| password | VARCHAR(255) | bcrypt |
| role_id | FK roles | |
| designation | VARCHAR(100) NULL | |
| department | VARCHAR(100) NULL | |
| reporting_manager_id | FK users NULL | **routes the request to the right Manager** |
| is_active | BOOLEAN | default 1 |
| last_login_at | TIMESTAMP NULL | |
| deleted_at | TIMESTAMP NULL | soft delete |

Index: `role_id`, `reporting_manager_id`.
`reporting_manager_id` exists because the workflow says "Manager Review" but never says *which* manager. Without it, routing is undefined.

---

## 5. `booking_requests` — the central table
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| request_no | VARCHAR(30) UNIQUE | `REQ/2025/00123`, generated |
| user_id | FK users | the requester |
| purpose | ENUM | `TRAINING` · `SELF` · `GUEST` |
| training_program | VARCHAR(150) NULL | required iff purpose=TRAINING |
| host_employee_id | FK users NULL | required iff purpose=GUEST |
| guest_of_name | VARCHAR(120) NULL | free-text host name if not a system user |
| check_in_date | DATE | |
| check_out_date | DATE | must be > check_in_date |
| nights | SMALLINT UNSIGNED | stored, = DATEDIFF(out, in) |
| total_members | SMALLINT UNSIGNED | >= 1 |
| preferred_room_type_id | FK room_types NULL | **preference only, never a guarantee** |
| rooms_needed | SMALLINT UNSIGNED | computed suggestion, admin may override |
| contact_mobile | VARCHAR(15) | snapshot at submit time |
| contact_email | VARCHAR(150) | snapshot |
| remarks | TEXT NULL | |
| status | VARCHAR(30) | `RequestStatus` enum value |
| submitted_at | TIMESTAMP NULL | |
| manager_id | FK users NULL | who decided |
| manager_acted_at | TIMESTAMP NULL | |
| manager_remarks | TEXT NULL | |
| more_info_note | TEXT NULL | what the Manager asked for |
| more_info_at | TIMESTAMP NULL | |
| resubmitted_at | TIMESTAMP NULL | |
| adg_id | FK users NULL | |
| adg_acted_at | TIMESTAMP NULL | |
| adg_remarks | TEXT NULL | |
| availability_checked_at | TIMESTAMP NULL | proves the §0 rule was honoured |
| availability_checked_by | FK users NULL | must be an admin |
| cancelled_at | TIMESTAMP NULL | |
| cancel_reason | VARCHAR(255) NULL | |
| deleted_at | TIMESTAMP NULL | soft delete |

Indexes: `status`, `user_id`, `(check_in_date, check_out_date)`, `manager_id`, `adg_id`, `purpose`.
CHECK constraint: `check_out_date > check_in_date`.

There is **no `room_id` on this table.** Rooms live in `allotments` because Decision 1 makes this one-to-many. Putting a room here was the single easiest schema mistake to make.

## 6. `request_occupants`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| booking_request_id | FK booking_requests | CASCADE |
| name | VARCHAR(120) | |
| age | TINYINT UNSIGNED NULL | |
| gender | ENUM NULL | `M` · `F` · `O` |
| relation | VARCHAR(60) NULL | e.g. spouse, colleague |
| is_primary | BOOLEAN | exactly one true per request |
| id_proof_type | ENUM NULL | `AADHAAR` · `PAN` · `PASSPORT` · `OFFICE_ID` · `OTHER` |
| id_proof_number | VARBINARY(255) NULL | **encrypted at rest** |
| id_proof_last4 | CHAR(4) NULL | for masked display |

Index: `booking_request_id`.
Row count must equal `total_members`. Enforced in `BookingRequestService`, not the DB.

## 7. `request_documents`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| booking_request_id | FK booking_requests | CASCADE |
| request_occupant_id | FK request_occupants NULL | whose proof |
| doc_type | ENUM | as above |
| original_filename | VARCHAR(255) | |
| stored_path | VARCHAR(255) | under `storage/app/private/id-proofs/` |
| mime_type | VARCHAR(100) | allow-list: pdf, jpg, png only |
| size_bytes | INT UNSIGNED | max 5 MB |
| sha256 | CHAR(64) | integrity + dedupe |
| uploaded_by | FK users | |

Index: `booking_request_id`.
`stored_path` is never rendered as a URL. Access only via an authorized controller that writes an `audit_logs` row per view.


---

## 8. `room_types`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| code | VARCHAR(30) UNIQUE | `DELUXE_AC` · `SUITE_AC` · `STANDARD_AC` · `NON_AC` |
| name | VARCHAR(80) | "Deluxe AC" |
| category | ENUM | `VIP` · `NORMAL` |
| default_capacity | TINYINT UNSIGNED | persons per room, drives `rooms_needed` |
| has_ac | BOOLEAN | |
| sort_order | TINYINT UNSIGNED | display order on screen 3.3 |
| is_active | BOOLEAN | |

A table, not an enum — so an admin can add a type without a deploy (Decision 4).

## 9. `rooms`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| room_number | VARCHAR(20) UNIQUE | "101" |
| room_type_id | FK room_types | |
| floor | TINYINT NULL | |
| block | VARCHAR(30) NULL | |
| capacity | TINYINT UNSIGNED NULL | override; NULL ⇒ use type default |
| status | ENUM | `ACTIVE` · `BLOCKED` · `MAINTENANCE` |
| block_reason | VARCHAR(255) NULL | |
| blocked_from | DATE NULL | date-ranged block |
| blocked_to | DATE NULL | |
| notes | TEXT NULL | |

Indexes: `room_type_id`, `status`.
`status=BLOCKED` supplies the **Blocked** column in infographic §7, which v1 omitted entirely. A block may be permanent (`status`) or date-ranged (`blocked_from/to`); the availability query respects both.

## 10. `tariffs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| room_type_id | FK room_types | |
| purpose | ENUM NULL | NULL = applies to all purposes |
| amount_per_night | DECIMAL(10,2) | |
| effective_from | DATE | |
| effective_to | DATE NULL | NULL = open-ended |
| is_active | BOOLEAN | |

Index: `(room_type_id, effective_from)`.
Resolution: most specific `purpose` match wins, then latest `effective_from` covering the date. Existence of this table is what makes the Revenue Report (§6) computable.

## 11. `allotments` — one row per room per request
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| allotment_no | VARCHAR(30) UNIQUE | `ALT/2025/00456` |
| booking_request_id | FK booking_requests | |
| room_id | FK rooms | |
| check_in_date | DATE | copied from request; may differ on extension |
| check_out_date | DATE | |
| occupants_count | TINYINT UNSIGNED | ≤ effective room capacity |
| status | ENUM | `ALLOTTED` · `CHECKED_IN` · `CHECKED_OUT` · `EARLY_CHECKOUT` · `CANCELLED` |
| rate_per_night | DECIMAL(10,2) | **snapshot** — tariff changes must not rewrite history |
| total_amount | DECIMAL(10,2) | rate × nights |
| allotted_by | FK users | admin |
| allotted_at | TIMESTAMP | |
| actual_check_in_at | DATETIME NULL | |
| actual_check_out_at | DATETIME NULL | |
| checked_in_by | FK users NULL | |
| checked_out_by | FK users NULL | |
| qr_token | CHAR(40) UNIQUE NULL | for QR check-in |
| cancel_reason | VARCHAR(255) NULL | |
| occupies | DATE NULL GENERATED | see below |

**Indexes (performance-critical):**
- `idx_overlap (room_id, status, check_in_date, check_out_date)` — serves the availability query
- `booking_request_id`
- `UNIQUE (room_id, occupies)` — the concurrency backstop

**The `occupies` generated column:**
```sql
occupies DATE GENERATED ALWAYS AS (
  CASE WHEN status IN ('ALLOTTED','CHECKED_IN') THEN check_in_date ELSE NULL END
) STORED
```
MySQL permits multiple NULLs in a unique index, so cancelled and checked-out rows are exempt while active rows cannot share a start date on the same room. **Honest limitation:** this stops identical start dates only, *not* partial overlaps. It is a safety net, not the primary defence — the transaction plus row lock in §14 is.

## 12. `stay_extensions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| booking_request_id | FK booking_requests | |
| allotment_id | FK allotments | |
| previous_check_out_date | DATE | |
| requested_check_out_date | DATE | > previous |
| status | ENUM | `REQUESTED` · `APPROVED` · `DENIED` |
| reason | TEXT NULL | |
| requested_by | FK users | |
| decided_by | FK users NULL | admin |
| decided_at | TIMESTAMP NULL | |
| denial_reason | VARCHAR(255) NULL | |

On approval the extension updates `allotments.check_out_date` and recomputes `total_amount` — **only after** the availability check passes for the additional nights on that same room.

## 13. Supporting tables

**`feedback`** — id, booking_request_id (unique), user_id, rating_cleanliness / rating_staff / rating_facilities / rating_overall (TINYINT 1–5), comments TEXT NULL, submitted_at.

**`audit_logs`** — id, auditable_type, auditable_id (polymorphic), action VARCHAR(60), from_status VARCHAR(30) NULL, to_status VARCHAR(30) NULL, actor_id FK users NULL, actor_role VARCHAR(30), remarks TEXT NULL, ip_address VARCHAR(45), user_agent VARCHAR(255), metadata JSON NULL, created_at.
Indexes: `(auditable_type, auditable_id)`, `actor_id`, `created_at`. **Append-only** — the application never issues UPDATE or DELETE here. This powers the History panel in screen 3.4.

**`notifications`** — id, user_id, booking_request_id NULL, event_key VARCHAR(60), channel ENUM(`EMAIL`,`SMS`,`IN_APP`), title, body TEXT, status ENUM(`PENDING`,`SENT`,`FAILED`,`READ`), attempts TINYINT, error TEXT NULL, sent_at NULL, read_at NULL.
Index: `(user_id, status)`, `event_key`.

**`email_templates`** — id, event_key VARCHAR(60) UNIQUE, subject, body_html, body_text, sms_text VARCHAR(320) NULL, placeholders JSON, is_active. Editable by Admin per Module 8.

**`holidays`** — id, date DATE UNIQUE, name, type ENUM(`PUBLIC`,`RESTRICTED`,`LOCAL`), year SMALLINT. Index `year`.

**`settings`** — id, `key` VARCHAR(80) UNIQUE, value TEXT, type ENUM(`STRING`,`INT`,`BOOL`,`JSON`), group VARCHAR(40), description.

---

## 14. The Availability Query (exact SQL — implement verbatim)

Run **only** by `AvailabilityService`, **only** for an admin, **only** when request status is `PENDING_ALLOTMENT` or `PARTIALLY_ALLOTTED`.

### Step 1 — count availability per room type (screen 3.3 summary)
```sql
SELECT rt.id, rt.name, rt.category,
       COUNT(r.id) AS total,
       SUM(CASE WHEN r.status = 'ACTIVE'
                 AND NOT (r.blocked_from IS NOT NULL
                          AND r.blocked_from < :to
                          AND :from < COALESCE(r.blocked_to, '9999-12-31'))
                 AND NOT EXISTS (
                       SELECT 1 FROM allotments a
                       WHERE a.room_id = r.id
                         AND a.status IN ('ALLOTTED','CHECKED_IN')
                         AND a.check_in_date < :to
                         AND :from < a.check_out_date)
                THEN 1 ELSE 0 END) AS available
FROM room_types rt
LEFT JOIN rooms r ON r.room_type_id = rt.id
WHERE rt.is_active = 1
GROUP BY rt.id, rt.name, rt.category
ORDER BY rt.sort_order;
```

### Step 2 — lock and fetch concrete rooms to allot
```sql
START TRANSACTION;

SELECT r.id, r.room_number
FROM rooms r
WHERE r.room_type_id = :type
  AND r.status = 'ACTIVE'
  AND NOT (r.blocked_from IS NOT NULL
           AND r.blocked_from < :to
           AND :from < COALESCE(r.blocked_to, '9999-12-31'))
  AND NOT EXISTS (
        SELECT 1 FROM allotments a
        WHERE a.room_id = r.id
          AND a.status IN ('ALLOTTED','CHECKED_IN')
          AND a.check_in_date < :to
          AND :from < a.check_out_date)
ORDER BY r.room_number
LIMIT :needed
FOR UPDATE;              -- locks the candidate room rows
```

The overlap and blocked predicates **must** be inside this WHERE clause. An earlier draft filtered only on `status='ACTIVE'` and re-checked overlap after locking — that locks the first N rooms by number whether or not they are free, and then allots zero rooms even though other rooms of the type are available. A spurious "no room" for a fully approved officer is a serious functional bug.

Then, **after** the lock is held, re-run the `NOT EXISTS` overlap test once more per locked room id before inserting. The in-WHERE filter selects the right candidates; the post-lock re-check is what defeats the race. Both are required — they do different jobs. Insert allotments, then `COMMIT`.

Never trust the Step 1 counts when writing — they are display-only and may be stale by milliseconds.

### The overlap predicate, isolated
```
a.check_in_date < :new_check_out  AND  :new_check_in < a.check_out_date
```
Half-open `[in, out)`. A stay ending on the 22nd and one starting on the 22nd do **not** overlap. Boundary cases that MUST be unit-tested:

| Existing | New | Overlaps? |
|---|---|---|
| 20→22 | 22→24 | **No** (checkout-day reuse) |
| 20→22 | 21→23 | Yes |
| 20→22 | 19→21 | Yes |
| 20→22 | 20→22 | Yes |
| 20→22 | 18→20 | **No** |
| 20→26 | 21→23 | Yes (fully contained) |

### Rooms needed
```
effective_capacity = room.capacity ?? room_type.default_capacity
rooms_needed       = CEIL(total_members / effective_capacity)
```
Stored on the request as a suggestion. The admin may allot more or fewer — allotment is at the authority's discretion (infographic §7 note).
