<?php

use App\Application\Services\IdProofRetentionService;
use App\Application\Services\StayReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled notifications — NOTIFICATIONS.md events 9 and 10.
|
| Requires the server to run `php artisan schedule:run` every minute (cron on
| Linux, Task Scheduler on Windows). Safe to run more than once a day: the
| service skips any reminder that has already been sent.
*/
Artisan::command('gh:stay-reminders {--date= : Treat this date (Y-m-d) as today, for catching up a missed run}',
    function (StayReminderService $reminders) {
        $date = $this->option('date');

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must be in Y-m-d format.');

            return 1;
        }

        $today = $date !== null ? Carbon::parse($date) : today();
        $result = $reminders->send($today);

        $this->info(sprintf(
            'Reminders for %s: %d check-in, %d check-out, %d already sent.',
            $today->format('d/m/Y'), $result['checkin'], $result['checkout'], $result['skipped'],
        ));

        return 0;
    })->purpose('Send the check-in (day before) and check-out (same morning) stay reminders');

// 07:30 in the application timezone (Asia/Kolkata): early enough to be read before
// the guest leaves for the day, late enough not to wake anyone with an SMS.
Schedule::command('gh:stay-reminders')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled stay reminders failed.'));

/*
| ID-proof retention — SECURITY.md section 2. Deletes uploaded identity
| documents and full identity numbers once a request is past the retention
| window (Settings → "Keep ID proofs for"). Always run --dry-run first when
| changing the window.
*/
Artisan::command('gh:purge-id-proofs {--dry-run : Report what would be purged without deleting anything}',
    function (IdProofRetentionService $retention) {
        $dry = (bool) $this->option('dry-run');
        $result = $retention->purge($dry);

        $this->info(sprintf(
            '%s %d request(s): %d file(s), %d identity number(s). Retention %d months (before %s).',
            $dry ? 'Would purge' : 'Purged',
            $result['requests'], $result['files'], $result['numbers'],
            (int) config('gh.id_proof_retention_months'), $retention->cutoff()->format('d/m/Y'),
        ));

        return 0;
    })->purpose('Delete ID proofs and full identity numbers past the retention window');

Schedule::command('gh:purge-id-proofs')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled ID-proof purge failed.'));

/*
| Deployment gate — SECURITY.md sections 3 and 6. Checks the settings that are
| correct to leave relaxed on a developer machine but must never reach
| production. Exits non-zero on any failure so a deploy script can stop on it.
*/
Artisan::command('gh:security-check', function () {
    $checks = [
        'APP_ENV is production' => app()->environment('production'),
        'APP_DEBUG is off (error pages leak nothing)' => ! config('app.debug'),
        'APP_KEY is set' => filled(config('app.key')),
        'APP_URL uses HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
        'Session cookie is Secure' => (bool) config('session.secure'),
        'Session cookie is HttpOnly' => (bool) config('session.http_only'),
        'Session cookie is SameSite=lax or strict' => in_array(config('session.same_site'), ['lax', 'strict'], true),
        'Session idle timeout is at most 30 minutes' => (int) config('session.lifetime') <= 30,
        'Bcrypt cost is at least 12' => (int) config('hashing.bcrypt.rounds', 12) >= 12,
        'PHP does not advertise its version (expose_php=Off)' => ! filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN),
        'Mail is not the log driver' => config('mail.default') !== 'log',
    ];

    $failed = 0;
    foreach ($checks as $label => $ok) {
        $this->line(($ok ? '  <info>PASS</info> ' : '  <error>FAIL</error> ').$label);
        $failed += $ok ? 0 : 1;
    }

    $this->newLine();
    $failed === 0
        ? $this->info('All deployment security checks passed.')
        : $this->error("{$failed} check(s) failed. Fix them before going live.");

    return $failed === 0 ? 0 : 1;
})->purpose('Verify production security settings (SECURITY.md sections 3 and 6)');
