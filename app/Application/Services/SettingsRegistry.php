<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Administrator-editable settings — ROUTES.md `/admin/settings`, SCHEMA.md 13.
 *
 * A fixed registry, not a free-form key/value editor: an administrator can only
 * change what is listed here, each value is validated to its type, and every
 * value maps onto a config('gh.*') key the application already reads. The
 * .env / config value is the default; a saved row overrides it at boot.
 *
 * SECRET rows (the Gmail refresh token) are deliberately not in the registry,
 * so they can never be displayed or overwritten from this screen.
 */
class SettingsRegistry
{
    /**
     * key => [config path, type, group, label, validation rules, help]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<int, string>, 5: string}>
     */
    public const DEFINITIONS = [
        'institution' => ['gh.institution', 'STRING', 'Institution', 'Institution name', ['required', 'string', 'max:120'], 'Printed on letters, emails and report headers.'],
        'building' => ['gh.building', 'STRING', 'Institution', 'Building', ['required', 'string', 'max:120'], ''],
        'city' => ['gh.city', 'STRING', 'Institution', 'City and state', ['required', 'string', 'max:120'], ''],
        'pin' => ['gh.pin', 'STRING', 'Institution', 'PIN code', ['required', 'regex:/^\d{6}$/'], 'Six digits.'],
        'id_proof_retention_months' => ['gh.id_proof_retention_months', 'INT', 'Privacy', 'Keep ID proofs for (months after checkout)', ['required', 'integer', 'between:1,120'], 'Uploaded identity documents are deleted this long after the stay ends (SECURITY.md §2).'],
        'notify_email' => ['gh.notify.email', 'BOOL', 'Notifications', 'Send email notifications', ['boolean'], ''],
        'notify_sms' => ['gh.notify.sms', 'BOOL', 'Notifications', 'Send SMS notifications', ['boolean'], 'Leave off until an SMS gateway is configured.'],
        'notify_in_app' => ['gh.notify.in_app', 'BOOL', 'Notifications', 'Show in-app notifications', ['boolean'], ''],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Override config from saved rows. Called once at boot; silently does
     * nothing before the settings table exists (fresh install, migrations).
     */
    public static function applyToConfig(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $rows = Setting::whereIn('key', array_keys(self::DEFINITIONS))->get()->keyBy('key');
        } catch (\Throwable) {
            return;   // no database yet
        }

        foreach ($rows as $key => $row) {
            config([self::DEFINITIONS[$key][0] => Setting::get($key)]);
        }
    }

    /** @return array<string, mixed> key => effective value */
    public function values(): array
    {
        return collect(self::DEFINITIONS)->map(fn ($d) => config($d[0]))->all();
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return collect(self::DEFINITIONS)->map(fn ($d) => $d[4])->all();
    }

    /**
     * @param  array<string, mixed>  $input  validated
     * @return array<int, string> keys that changed
     */
    public function save(array $input, User $admin): array
    {
        if (! $admin->hasPermission('settings.manage')) {
            throw new RuntimeException('Only an administrator may change settings.');
        }

        return DB::transaction(function () use ($input, $admin) {
            $before = $this->values();
            $changed = [];

            foreach (self::DEFINITIONS as $key => [$path, $type, $group, $label]) {
                $new = match ($type) {
                    'BOOL' => (bool) ($input[$key] ?? false),
                    'INT' => (int) $input[$key],
                    default => trim((string) $input[$key]),
                };

                if ($new === $before[$key]) {
                    continue;
                }

                Setting::put($key, $new, $type, $group, $label);
                config([$path => $new]);
                $changed[$key] = ['from' => $before[$key], 'to' => $new];
            }

            if ($changed !== []) {
                // Values are recorded: none of these settings is personal data or a secret.
                $this->audit->record($admin, 'SETTINGS_UPDATED', $admin, metadata: $changed);
            }

            return array_keys($changed);
        });
    }
}
