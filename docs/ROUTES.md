# ROUTES.md — Route Map

Every route the system exposes. Nothing outside this list. All routes are `web` (session) with CSRF; `auth` unless marked public.

Middleware shorthand: `role:x` = `EnsureRole` middleware. Ownership is checked in the policy, not the route.

---

## Public
| Method | URI | Controller | Notes |
|---|---|---|---|
| GET | `/login` | `Auth\LoginController@show` | screen 3.1 |
| POST | `/login` | `Auth\LoginController@login` | throttle 5/min per IP+email |
| POST | `/logout` | `Auth\LoginController@logout` | auth |
| GET | `/forgot-password` | `Auth\PasswordController@request` | |
| POST | `/forgot-password` | `Auth\PasswordController@email` | throttle 3/min; **always returns generic success** (no account enumeration) |
| GET | `/reset-password/{token}` | `Auth\PasswordController@reset` | |
| POST | `/reset-password` | `Auth\PasswordController@update` | |
| GET | `/` | view `public.landing` | "Guest House Portal" landing: requisition card + Admin login card |
| GET | `/book` | `PublicBookingController@create` | public "Room & Stay Details" requisition form |
| POST | `/book` | `PublicBookingController@store` | throttle 10/min per IP; 5 failed ID matches per Employee ID → 10-min lockout; generic mismatch message |
| GET | `/book/submitted` | `PublicBookingController@submitted` | shows the request number from flash data only |

Registration is **not** public. Users are created by Admin (Module 1). The infographic's "Register" link maps to a request-access form, not self-service account creation.

The public requisition creates **requests, not accounts**. The applicant is matched by Employee ID / PPO No. + the mobile on their account (active users holding `request.create` only), and the request is raised in their name into the normal `PENDING_MANAGER` queue. Viewing, editing, cancelling and responding to "more info" still require sign-in.

---

## Shared (any authenticated role)
| Method | URI | Controller | Notes |
|---|---|---|---|
| GET | `/dashboard` | `DashboardController@index` | branches by role |
| GET | `/notifications` | `NotificationController@index` | own only |
| POST | `/notifications/{id}/read` | `NotificationController@markRead` | own only |
| GET | `/requests/{request}` | `Shared\RequestViewController@show` | policy `view`; Users see own, Manager sees team, ADG/Admin see all. **Never includes availability data.** |
| GET | `/documents/{document}` | `Shared\DocumentController@show` | streams from private storage; policy-gated; writes an audit row per access |

---

## User — `role:user`
| Method | URI | Controller | Transition |
|---|---|---|---|
| GET | `/my/requests` | `User\BookingRequestController@index` | |
| GET | `/my/requests/create` | `@create` | screen 3.2 |
| POST | `/my/requests` | `@store` | T1 |
| GET | `/my/requests/{request}/edit` | `@edit` | only `DRAFT` or `MORE_INFO_MANAGER` |
| PUT | `/my/requests/{request}` | `@update` | |
| POST | `/my/requests/{request}/resubmit` | `@resubmit` | **T5** |
| POST | `/my/requests/{request}/cancel` | `@cancel` | T13 / T19 |
| POST | `/my/requests/{request}/extension` | `User\ExtensionController@store` | T16 |
| GET | `/my/requests/{request}/feedback` | `User\FeedbackController@create` | after checkout |
| POST | `/my/requests/{request}/feedback` | `@store` | |
| GET | `/my/requests/{request}/letter` | `@letter` | allotment PDF, own only |

The user area has **no availability route of any kind.** This is the structural enforcement of Core Rule 2.

---

## Manager — `role:manager`
| Method | URI | Controller | Transition |
|---|---|---|---|
| GET | `/manager/requests` | `Manager\ReviewController@index` | queue: `PENDING_MANAGER` |
| GET | `/manager/requests/{request}` | `@show` | screen 3.4 + history + room board (Block A only, config `gh.room_board_blocks`) |
| POST | `/manager/requests/{request}/approve` | `@approve` | T2; optional `room_ids[]` holds rooms from the room board (decision 10) |
| POST | `/manager/requests/{request}/reject` | `@reject` | T3, remarks required |
| POST | `/manager/requests/{request}/more-info` | `@moreInfo` | T4, note required |

---

## ADG — `role:adg`
| Method | URI | Controller | Transition |
|---|---|---|---|
| GET | `/adg/requests` | `Adg\ApprovalController@index` | queue: `PENDING_ADG` |
| GET | `/adg/requests/{request}` | `@show` | |
| POST | `/adg/requests/{request}/approve` | `@approve` | T6 |
| POST | `/adg/requests/{request}/reject` | `@reject` | T7, remarks required |
| GET | `/adg/inventory` | `Adg\InventoryController@index` | read-only |

**No `more-info` route exists for the ADG.** Not hidden in the UI — absent from the route table entirely, so it returns 404/403 and cannot be reached by crafted POST. This is Decision 5 enforced at three layers: no route, no permission seed, no transition in the state machine.

---

## Admin — `role:admin`
### Availability & allotment
| Method | URI | Controller | Notes |
|---|---|---|---|
| GET | `/admin/requests/{request}/availability` | `Admin\AvailabilityController@show` | screen 3.3. **Aborts 409 unless status is `PENDING_ALLOTMENT` or `PARTIALLY_ALLOTTED`** |
| POST | `/admin/requests/{request}/availability` | `@search` | date-range query; stamps `availability_checked_at/by` |
| GET | `/admin/requests/{request}/allot` | `Admin\AllotmentController@create` | screen 3.5 |
| POST | `/admin/requests/{request}/allot` | `@store` | T8/T9/T11; locked transaction |
| POST | `/admin/requests/{request}/no-room` | `@markNoRoom` | T10 |
| POST | `/admin/requests/{request}/recheck` | `@recheck` | T20 |
| DELETE | `/admin/allotments/{allotment}` | `@destroy` | release one room, recompute status |
| GET | `/admin/allotments/{allotment}/letter` | `@letter` | PDF + QR |

### Stay
| POST | `/admin/allotments/{allotment}/check-in` | `Admin\StayController@checkIn` | T12 |
| POST | `/admin/allotments/{allotment}/check-out` | `@checkOut` | T14 |
| POST | `/admin/allotments/{allotment}/early-checkout` | `@earlyCheckOut` | T15 |
| POST | `/admin/extensions/{extension}/approve` | `Admin\ExtensionController@approve` | T17, re-verifies availability |
| POST | `/admin/extensions/{extension}/deny` | `@deny` | T18 |
| POST | `/admin/check-in/qr` | `Admin\StayController@qrCheckIn` | scans `qr_token` |

### Masters
Built without `show` or `destroy`: users and rooms are referenced by requests, allotments and audit rows, so they are deactivated or blocked, never deleted. Tariffs are history, so they have no edit either — a rate is revised by adding a row (which closes the previous open-ended row the day before) and retired by setting its end date.

| GET/POST/PUT | `/admin/rooms` | `Admin\RoomController` | index, create, store, edit, update |
| POST | `/admin/rooms/{room}/block` | `@block` | BLOCKED / MAINTENANCE, or a date range; refused if the room is allotted in the window |
| POST | `/admin/rooms/{room}/unblock` | `@unblock` | |
| GET/POST/PUT | `/admin/room-types` | `Admin\RoomTypeController` | index, create, store, edit, update; retire via `is_active` |
| GET/POST | `/admin/tariffs` | `Admin\TariffController` | index, store (new revision) |
| POST | `/admin/tariffs/{tariff}/end` | `@end` | sets `effective_to` |
| GET/POST/PUT | `/admin/users` | `Admin\UserController` | index, create, store, edit, update; incl. `reporting_manager_id` |
| resource | `/admin/holidays` | `Admin\HolidayController` | |
| resource | `/admin/email-templates` | `Admin\EmailTemplateController` | |
| GET/PUT | `/admin/settings` | `Admin\SettingsController` | |
| GET | `/admin/inventory` | `Admin\RoomInventoryController@index` | full Total/Available/Booked/Blocked, infographic §7 |
| GET | `/admin/audit-logs` | `Admin\AuditLogController@index` | read-only, filterable |

### As built (Phase 9)
| Method | URI | Controller | Notes |
|---|---|---|---|
| GET/POST/PUT/DELETE | `/admin/holidays` | `Admin\HolidayController` | index, store, update, destroy; audited. Nothing references a holiday, so delete is allowed |
| GET/PUT | `/admin/settings` | `Admin\SettingsController@edit/update` | fixed registry (`SettingsRegistry`); overrides `config('gh.*')` at boot; secrets not editable here |
| GET/PUT | `/admin/email-templates` | `Admin\EmailTemplateController` | index, edit, update only — the 19 templates are fixed by `NotificationEvent`. Refuses unknown placeholders, identity data and active HTML |
| POST | `/admin/occupants/{occupant}/reveal` | `Admin\IdProofRevealController` | reason required, throttle 10/min, `no-store`, audited as `ID_PROOF_REVEALED` without the number |
| GET | `/adg/inventory` | `Shared\ManagementInventoryController@index` | tonight's counts only; the service method takes no dates. (The Manager's copy was removed on 2026-09-29; the Manager sees rooms only on the review screen's room board.) |
| GET/POST | `/my/requests/{request}/feedback` | `User\FeedbackController` | owner only, after checkout, once |
| GET | `/dashboard` | `Admin\DashboardController@index` | admin tiles + charts |
| GET | `/reports`, `/reports/{type}`, `/reports/{type}/export/{xlsx\|pdf}` | `Shared\ReportController` | see Reports below |

Scheduled (`routes/console.php`; needs `php artisan schedule:run` every minute): `gh:stay-reminders` 07:30 daily, `gh:purge-id-proofs` 02:00 daily. Deploy gate: `gh:security-check`.

---

## Reports — `role:manager,adg,admin` (scope differs)
| GET | `/reports` | `ReportController@index` |
| GET | `/reports/{type}` | `@show` — type ∈ `occupancy` · `booking` · `allotment` · `user-wise` · `purpose-wise` · `revenue` · `feedback` |
| GET | `/reports/{type}/export/{format}` | `@export` — format ∈ `xlsx` · `pdf` |

`revenue` is admin-only. Users reach only their own history via `/my/requests`.

---

## Permission slugs (seeded to `role_permission`)

```
user:     request.create  request.cancel.own  request.resubmit
          extension.request  feedback.submit  request.view.own
manager:  request.view.team  request.approve.manager
          request.reject.manager  request.moreinfo.manager
          inventory.view  report.view.team  room.allot.review
adg:      request.view.all  request.approve.adg  request.reject.adg
          inventory.view  report.view.all
admin:    request.view.all  availability.check  availability.recheck
          room.allot  room.release  room.block  inventory.view
          stay.manage  extension.decide  master.manage
          settings.manage  report.view.all  report.revenue  audit.view
```

Slug-to-route guards, so none is orphaned:
- `availability.check` → the two `/admin/requests/{id}/availability` routes
- `availability.recheck` → `/admin/requests/{id}/recheck` (T20)
- `inventory.view` → `/admin/inventory`, `/manager/inventory`, `/adg/inventory` (date-range picker rendered for admin only)
- `report.view.team` → `/reports/*` scoped to the manager's reportees
- `request.view.all` → `/requests/{request}` for ADG **and Admin**

`request.moreinfo.adg` **does not exist**. `availability.check` is granted to `admin` only — never to `user`, `manager`, or `adg`.
