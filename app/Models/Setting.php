<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /**
     * Read a setting, decrypting when the row is marked SECRET.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();

        if ($row === null || $row->value === null) {
            return $default;
        }

        return match ($row->type) {
            'SECRET' => static::tryDecrypt($row->value),
            'INT' => (int) $row->value,
            'BOOL' => filter_var($row->value, FILTER_VALIDATE_BOOLEAN),
            'JSON' => json_decode($row->value, true),
            default => $row->value,
        };
    }

    /**
     * Write a setting. Values stored with type SECRET are encrypted at rest with
     * APP_KEY, so a database dump does not leak a live credential.
     */
    public static function put(
        string $key,
        mixed $value,
        string $type = 'STRING',
        string $group = 'general',
        ?string $description = null,
    ): void {
        $stored = match ($type) {
            'SECRET' => $value === null ? null : Crypt::encryptString((string) $value),
            'JSON' => json_encode($value),
            'BOOL' => $value ? '1' : '0',
            default => $value === null ? null : (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type, 'group' => $group, 'description' => $description],
        );
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
    }

    /**
     * Tolerates a value that was written before encryption was introduced, so a
     * misconfigured row degrades to "unusable credential" rather than a fatal
     * decrypt error on every request.
     */
    private static function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
