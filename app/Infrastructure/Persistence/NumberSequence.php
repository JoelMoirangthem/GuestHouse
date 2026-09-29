<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, deadlock-free document numbering. See the number_sequences
 * migration for why "last number + 1 FOR UPDATE" was replaced.
 *
 * Call inside the transaction that inserts the numbered row: the counter row
 * stays locked until that transaction commits, so a rolled-back insert also
 * rolls back its number and the series has no gaps.
 */
final class NumberSequence
{
    public static function next(string $series): int
    {
        // LAST_INSERT_ID(expr) stores expr for THIS connection only, so the value
        // read back is ours even when other sessions increment concurrently.
        DB::statement(
            'INSERT INTO number_sequences (name, value, updated_at) VALUES (?, LAST_INSERT_ID(1), NOW())
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1), updated_at = NOW()',
            [$series],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
    }

    /** "REQ/2026/00042" */
    public static function formatted(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return sprintf('%s/%d/%05d', $prefix, $year, self::next("{$prefix}/{$year}"));
    }
}
