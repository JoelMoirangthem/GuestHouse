# Guest House Booking & Approval System

Room booking, two-level approval, allotment, stay management and reporting for
Pragya Bhawan, NADT, RC, DTRTI, Lucknow.

Laravel 12 · PHP 8.2 · MySQL 8 · Tailwind CSS 4 + Alpine.js · DomPDF · PhpSpreadsheet

The specification is in [`docs/`](docs/README.md). Start with `docs/PLAN.md`.

## Requirements

- PHP 8.2 with the `gd`, `pdo_mysql`, `mbstring`, `zip` and `bcmath` extensions (`bcmath` is needed to allot rooms)
- MySQL 8 (the double-booking guard relies on InnoDB row locks and a generated-column unique index)
- Composer, Node 20+

## Setup

```bash
composer install
npm ci && npm run build
cp .env.example .env         # then set DB_*, MAIL_*, GH_* values
php artisan key:generate
php artisan migrate --seed   # roles, sample users, rooms, tariffs, templates, holidays
php artisan serve
```

Seeded accounts use the development password in `database/seeders/UserSeeder.php`. Change or remove them before any shared deployment.

## Tests

The suite runs against a separate MySQL database, `gesthouse_test`, using the `DB_USERNAME` / `DB_PASSWORD` from your `.env`.

```bash
php artisan test
```

`DoubleBookingRaceTest` starts real PHP child processes on separate connections, so it needs MySQL; it is skipped on any other driver.

## Scheduled jobs

Run the scheduler every minute (cron on Linux, Task Scheduler on Windows):

```bash
php artisan schedule:run
```

| Command | When | Purpose |
|---|---|---|
| `gh:stay-reminders` | 07:30 daily | check-in reminder the day before, check-out reminder the same morning |
| `gh:purge-id-proofs` | 02:00 daily | delete ID proofs and full identity numbers past the retention window (`--dry-run` to preview) |

## Going live

```bash
php artisan gh:security-check
```

It fails on development settings. Before production: `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, a real mail transport, and `expose_php = Off` in php.ini. See `docs/SECURITY.md` §7 for the full checklist.
