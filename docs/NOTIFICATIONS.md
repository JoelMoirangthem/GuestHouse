# NOTIFICATIONS.md — Event → Channel → Recipient Matrix

Derived from infographic §5 (nine notification types). Dispatched by listeners after commit, never inline in a service.

---

## 1. Architecture

```
Service completes transition (inside DB::transaction)
   └─ DB::afterCommit  →  NotificationDispatcher::dispatch(event, request)
        └─ resolve recipients  →  render template  →  write notifications row
             └─ foreach enabled channel:
                  InAppChannel · EmailChannel · SmsChannel
```

**AS BUILT — deviation from the original draft.** This document first described
Event classes plus Listener classes. The implementation calls the dispatcher
directly from the service inside `DB::afterCommit()`, because fourteen event
classes and fourteen listeners would have been ceremony around a single method
call. The property that actually mattered — *nothing is sent until the
transaction commits, and no send failure can roll back an approval* — is
preserved exactly, and is what the tests assert.

`NotificationChannel` contract:
```php
interface NotificationChannel {
    public function key(): string;                    // 'EMAIL' | 'SMS' | 'IN_APP'
    public function send(NotificationPayload $p): bool;  // MUST NOT throw
    public function isEnabled(): bool;                // from config/gh.php
}
```

Adding WhatsApp later = one new class + one line in `DomainServiceProvider`. No
existing file changes. That is the Open/Closed payoff.

**A notifications row is written BEFORE delivery is attempted.** If the mail
server is down there is still a record that the recipient should have been told,
and the in-app bell shows it regardless of what email did.

### Real-time delivery in the browser

The header bell polls `GET /notifications/feed` every `NOTIFY_POLL_SECONDS`
(default 10) and updates the badge, dropdown and toast **without a page reload**.

Polling rather than WebSockets is deliberate. Laravel Reverb would need a second
long-lived process to run and stay running, and government networks routinely
block or proxy-break WebSocket upgrades. For a few dozen internal users a small
indexed query on a ten-second timer gives the same "no refresh" experience with
no extra infrastructure to operate. Cost is bounded three ways: the query is
covered by `idx_bell`, it returns at most ten rows, and polling stops entirely
while the browser tab is hidden.

---

## 2. Event Matrix

| # | `event_key` | Trigger | Email | SMS | In-App | Recipients |
|---|---|---|---|---|---|---|
| 1 | `request.submitted` | T1 | ✅ | — | ✅ | Manager (primary), requester (ack) |
| 2 | `request.resubmitted` | T5 | ✅ | — | ✅ | Manager |
| 3 | `request.more_info` | T4 | ✅ | ✅ | ✅ | Requester |
| 4 | `request.approved.manager` | T2 | ✅ | — | ✅ | ADG, requester |
| 5 | `request.rejected` | T3 / T7 | ✅ | ✅ | ✅ | Requester (+ Manager if ADG rejected) |
| 6 | `request.approved.adg` | T6 | ✅ | — | ✅ | **Admin** (action needed: check availability), requester |
| 7 | `rooms.allotted` | T8/T9/T11 | ✅ | ✅ | ✅ | Requester — includes room numbers + PDF letter |
| 8 | `rooms.none_available` | T10 | ✅ | ✅ | ✅ | Requester, ADG |
| 9 | `stay.checkin_reminder` | scheduled, 1 day before | ✅ | ✅ | ✅ | Requester |
| 10 | `stay.checkout_reminder` | scheduled, morning of checkout | ✅ | ✅ | ✅ | Requester |
| 11 | `stay.checked_in` | T12 | — | — | ✅ | Requester |
| 12 | `stay.extension_requested` | T16 | ✅ | — | ✅ | **Admin** (action needed: decide extension) |
| 13 | `stay.extended` | T17 | ✅ | ✅ | ✅ | Requester |
| 14 | `stay.extension_denied` | T18 | ✅ | ✅ | ✅ | Requester |
| 15 | `stay.checked_out` | T14 | ✅ | — | ✅ | Requester — includes feedback link |
| 16 | `stay.early_checkout` | T15 | ✅ | — | ✅ | Requester, Admin |
| 17 | `request.cancelled` | T13/T19 | ✅ | — | ✅ | **Requester**, Admin, Manager |
| 18 | `availability.recheck` | T20 | ✅ | — | ✅ | Requester |
| 19 | `announcement.general` | manual by Admin | ✅ | optional | ✅ | selected roles / all |

Three of these were missing in an earlier draft and are worth calling out, because each was a real functional hole:
- **#12** — without it, a user requests an extension and no admin is ever told, so the request silently rots.
- **#15** — normal checkout produced no notification at all, so the feedback loop in workflow step 7 could never start.
- **#17** — the requester was omitted from cancellation notices, so an admin-initiated cancellation of an allotted room left the guest unaware.

SMS is reserved for events that need action or are time-critical, since SMS has a real per-message cost. Email carries everything.

Events 9 and 10 are the only scheduled ones — a daily `php artisan schedule:run` command. Everything else is transition-driven.

**As built:** `php artisan gh:stay-reminders`, scheduled daily at 07:30 IST (`routes/console.php`). The server must run `php artisan schedule:run` every minute (cron on Linux, Task Scheduler on Windows). #9 goes to `ALLOTTED` requests arriving tomorrow — not `PARTIALLY_ALLOTTED`, which cannot check in (decision 9). #10 goes to `CHECKED_IN` / `EXTENSION_REQUESTED` stays departing today. Idempotent: a check-in reminder is sent once per request and a check-out reminder once per departure date, so a re-run sends nothing twice while an approved extension still gets a reminder on its new date. A missed day can be caught up with `--date=Y-m-d`.

---

## 2.1 Event class → `event_key` mapping (authoritative)

Listeners resolve keys from this table. Never infer a key from a class name.

| Event class (WORKFLOW.md §4) | `event_key` | Transition |
|---|---|---|
| `RequestSubmitted` | `request.submitted` | T1 |
| `RequestResubmitted` | `request.resubmitted` | T5 |
| `MoreInfoRequested` | `request.more_info` | T4 |
| `ManagerApproved` | `request.approved.manager` | T2 |
| `RequestRejected` | `request.rejected` | T3, T7 |
| `AdgApproved` | `request.approved.adg` | T6 |
| `RoomsAllotted` | `rooms.allotted` | T8, T9, T11 |
| `NoRoomAvailable` | `rooms.none_available` | T10 |
| `AvailabilityRecheckStarted` | `availability.recheck` | T20 |
| `GuestCheckedIn` | `stay.checked_in` | T12 |
| `GuestCheckedOut` | `stay.checked_out` / `stay.early_checkout` | T14 / T15 |
| `ExtensionRequested` | `stay.extension_requested` | T16 |
| `ExtensionDecided` | `stay.extended` / `stay.extension_denied` | T17 / T18 |
| `RequestCancelled` | `request.cancelled` | T13, T19 |
| *(scheduled)* | `stay.checkin_reminder` | — |
| *(scheduled)* | `stay.checkout_reminder` | — |
| *(manual)* | `announcement.general` | — |

`GuestCheckedOut` and `ExtensionDecided` each carry a boolean on the event that selects between two keys. Every key here must have a matching row in `email_templates`.

---

## 3. Template Placeholders

Stored in `email_templates.placeholders` (JSON) and validated on save so a template can never reference an unknown token.

Universal: `{{request_no}}` `{{user_name}}` `{{purpose}}` `{{check_in_date}}` `{{check_out_date}}` `{{nights}}` `{{total_members}}` `{{status}}` `{{action_url}}`

Allotment-specific: `{{allotment_no}}` `{{room_numbers}}` `{{room_type}}` `{{rate_per_night}}` `{{total_amount}}`

Decision-specific: `{{decided_by}}` `{{remarks}}` `{{more_info_note}}` `{{denial_reason}}`

Rendering rule: unknown placeholder → log a warning and render empty. Never leak a raw `{{token}}` to a recipient.

---

## 4. Non-negotiables

- **Never** put an Aadhaar number, ID-proof number, or a document link in an email or SMS. Notifications carry a `{{action_url}}` requiring login instead.
- SMS body ≤ 320 chars (2 segments), stored in `email_templates.sms_text`.
- In tests, all channels are bound to fakes. Zero real sends, asserted by `NotificationChannel` spies.
- Notification failures are retried 3× with backoff, then marked `FAILED` and surfaced on the admin dashboard.
