# SECURITY.md — Requirements & Checklist

This is a government system handling **Aadhaar numbers and uploaded identity documents**. Treat all of the below as mandatory, not advisory. Phase 9 does not pass until every box is ticked.

---

## 1. Threat model — what actually goes wrong here

| Threat | Impact | Control |
|---|---|---|
| ID-proof file reachable by public URL | Aadhaar leak; the worst realistic failure | Private storage + gated controller (§2) |
| A user enumerates availability via crafted request | Breaks Core Rule 2, the system's defining constraint | No route exists; policy denies; §4 |
| ADG posts to a More-Info endpoint | Violates Decision 5 | No route, no permission, no transition (§4) |
| Concurrent allotment double-books a room | Two officers, one room | Lock + re-verify + unique index |
| IDOR on `/requests/{id}` | Cross-department data exposure | Policy on every read (§4) |
| Credential stuffing on login | Account takeover | Throttle + strong password policy (§3) |
| Insider tampering with approval history | Undetectable fraud | Append-only `audit_logs` (§5) |
| Aadhaar in an email or SMS | Irreversible leak to third-party carriers | Prohibited by NOTIFICATIONS.md §4 |

---

## 2. Sensitive data handling

**ID-proof documents**
- Stored at `storage/app/private/id-proofs/{year}/{request_no}/{uuid}.{ext}` — outside `public/`, never symlinked.
- Filename is a generated UUID. The original name is kept only in the DB column.
- Served solely by `Shared\DocumentController@show`: authenticate → authorize via policy → write `audit_logs` row → `Storage::download()`.
- Upload allow-list by **MIME sniff, not extension**: `application/pdf`, `image/jpeg`, `image/png`. Max 5 MB.
- `sha256` stored for integrity verification.
- Re-encode or strip EXIF from uploaded images (may carry GPS).

**Aadhaar / ID numbers**
- Column `request_occupants.id_proof_number` is `VARBINARY`, encrypted with Laravel's `Crypt` (APP_KEY, AES-256-GCM).
- `id_proof_last4` stored separately in clear for display.
- UI and **all reports and exports** show `XXXX XXXX 1234`. Full value is never rendered — not even for Admin, unless an explicit "reveal" action is taken, which is itself audited.
- Aadhaar numbers must not appear in logs, exception traces, or `metadata` JSON.

**Retention**
- ID proofs purged 12 months after `CHECKED_OUT` via a scheduled command. Retention window lives in `settings`.

---

## 3. Authentication

- Bcrypt (Laravel default), cost ≥ 12.
- Password policy: min 10 chars, mixed case, digit, symbol; blocked against a common-password list; no reuse of last 3.
- Login throttle: 5 attempts/min per IP+email, then lockout with exponential backoff.
- Forgot-password: throttle 3/min, single-use token, 60-min expiry, **generic response always** so accounts cannot be enumerated.
- Session: `SESSION_SECURE_COOKIE=true`, `HttpOnly`, `SameSite=Lax`, 30-min idle timeout, session regenerated on login (fixation defence).
- Force logout of all sessions on password change.
- No self-registration. Admin provisions accounts.
- Public requisition (`POST /book`): no sign-in; the employee is identified by Employee ID / PPO No. + registered mobile. Accepted risk: anyone who knows both can raise a request in that employee's name; the Manager review is the control. Mitigations: IP throttle, per-Employee-ID lockout, one generic mismatch message, notifications go only to the account's email, and nothing about existing requests is revealed.
- Optional but recommended for Admin/ADG: TOTP 2FA.

---

## 4. Authorization — defence in depth

Three independent layers must all agree. Hiding a button is **not** access control.

1. **Route layer** — `role:` middleware; privileged routes simply do not exist for wrong roles (e.g. no ADG more-info route at all).
2. **Permission layer** — seeded `role_permission` rows. `availability.check` exists only for `admin`. `request.moreinfo.adg` does not exist.
3. **Policy layer** — `BookingRequestPolicy` checks ownership/scope *and* current status on every action. `AvailabilityService` re-asserts admin role and `PENDING_ALLOTMENT`/`PARTIALLY_ALLOTTED` status even when called internally.

Mandatory feature tests: for each of the four roles, assert 403 on every route belonging to the other three.

---

## 5. Audit integrity

- `audit_logs` is append-only. No model events, no service, and no controller issues UPDATE or DELETE against it.
- Every row records `actor_id`, `actor_role`, `ip_address`, `user_agent`, `from_status`, `to_status`.
- Logged beyond transitions: document views, Aadhaar reveals, login success/failure, role or permission changes, room blocks, tariff edits.
- DB user for the app should lack DELETE privilege on `audit_logs` where the hosting allows it.

---

## 6. Application hardening

- CSRF on every state-changing form (Laravel default — do not except any route).
- All queries via Eloquent/query builder bindings. Raw SQL from SCHEMA.md §14 uses **named bindings only**; no string interpolation of dates.
- Blade `{{ }}` escaping everywhere; `{!! !!}` is forbidden outside a reviewed, sanitised rich-text field.
- Validate every input in a FormRequest. Dates: `check_out_date` must be `after:check_in_date`; both `date_format:Y-m-d`.
- Mass-assignment: explicit `$fillable`. `status`, `request_no`, and all `*_by`/`*_at` workflow columns are **never** fillable — only services set them.
- `APP_DEBUG=false` in production; custom 403/404/500 pages that leak no stack traces.
- Security headers: HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and a CSP once asset origins are fixed.
- HTTPS enforced; valid SSL per the infographic's cost sheet.
- Secrets in `.env` only. `.env` git-ignored. No credentials in seeders or committed config.
- Pin dependency versions; run `composer audit` before each deploy.

---

## 7. Phase 9 sign-off checklist

Each box names the evidence that ticks it. Items marked **(deploy)** are production settings: they are correct to leave relaxed on a developer machine, and `php artisan gh:security-check` must pass on the server before go-live.

- [x] Direct URL to a stored ID proof returns 403/404, verified manually — live server returned 403 for `/storage/app/private/...`; `DocumentAccessTest::stored_documents_are_not_reachable_by_a_guessed_public_url`
- [x] Every document access produces an `audit_logs` row — `DOCUMENT_VIEWED` on the parent request; `DocumentAccessTest::every_document_access_is_recorded` (moved from the text log in Phase 9)
- [x] Aadhaar masked in UI, PDF, Excel export — `RequestOccupant::maskedIdProof()` in every view; `AllotmentLetterTest::the_letter_never_prints_identity_numbers`; `ReportsTest::no_identity_number_appears_in_any_report_or_export`. The full value is shown only through the audited, reason-required reveal (`IdentityDataLifecycleTest`)
- [x] Aadhaar absent from every email and SMS template — `NotificationDispatcherTest`; the template editor refuses identity references, 12-digit numbers and document links (`AdminConfigurationTest`)
- [x] Cross-role 403 matrix test passes for all four roles — `RoleIsolationMatrixTest`, `AdminMastersTest`, `AdminConfigurationTest`, `PlatformWalkthroughTest` (every GET route as every role)
- [x] No availability route reachable by user/manager/adg (asserted) — `AvailabilityAccessTest`; the only exceptions are the two dateless, read-only inventory screens, and `ManagementInventoryTest` proves a date range cannot be passed through
- [x] ADG more-info attempt returns 403/404 (asserted) — `ApprovalChainTest`
- [x] Concurrent double-booking test proves the lock holds — `DoubleBookingRaceTest`, separate PHP processes on separate connections
- [x] Login and forgot-password throttling verified — `AuthenticationTest::login_is_throttled_after_five_failed_attempts`, `SecurityHardeningTest::forgot_password_is_throttled_after_three_requests_a_minute`
- [x] Forgot-password does not reveal whether an account exists — `AuthenticationTest`; the password-reuse check runs only after the reset token has been validated
- [x] `APP_DEBUG=false`; error pages leak nothing — custom `resources/views/errors/*` print no exception detail for 5xx (`SecurityHardeningTest`). **(deploy)** `APP_DEBUG=false` itself is checked by `gh:security-check`
- [x] Secrets absent from the repository — `.env` git-ignored; database credentials removed from `phpunit.xml`; the Gmail refresh token is encrypted in `settings` and unreachable from the settings screen
- [x] `audit_logs` has no update/delete code path — model guards (`ApprovalChainTest`), no write route (`AdminConfigurationTest`), source scan (`SecurityHardeningTest`)

Also added in Phase 9: EXIF/GPS stripping of uploaded photos with a decompression-bomb guard; sign-in success and failure audited; no reuse of the last 3 passwords; Content-Security-Policy (no inline scripts); `X-Powered-By` removed; ID-proof retention purge (`gh:purge-id-proofs`, nightly).

**Open, by decision:** TOTP 2FA for Admin/ADG (§3, "optional but recommended") is not built. **(deploy)** `expose_php=Off` in php.ini, HTTPS with a valid certificate, `SESSION_SECURE_COOKIE=true`, and a database user without DELETE on `audit_logs` are server configuration.
