<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Password reuse rule — SECURITY.md section 3: "no reuse of last 3".
 *
 * Recording happens in a User model hook, so every path that changes a
 * password (reset link, admin reset, seeders) keeps the history — none can
 * forget to. Checking happens in the two places a person chooses a password.
 */
class PasswordHistory
{
    public const REMEMBER = 3;

    public const MESSAGE = 'Choose a password you have not used for your last 3 passwords.';

    /** True when the plain password matches the current or one of the recent ones. */
    public static function wasRecentlyUsed(User $user, string $plain): bool
    {
        return DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::REMEMBER)
            ->pluck('password')
            ->contains(fn (string $hash) => Hash::check($plain, $hash));
    }

    /** Called from the User model after a password is saved. */
    public static function record(User $user): void
    {
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => $user->getAuthPassword(),
            'created_at' => now(),
        ]);

        // Keep only what the rule needs; older hashes serve no purpose.
        $keep = DB::table('password_histories')->where('user_id', $user->id)
            ->orderByDesc('id')->limit(self::REMEMBER)->pluck('id');

        DB::table('password_histories')->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
    }
}
