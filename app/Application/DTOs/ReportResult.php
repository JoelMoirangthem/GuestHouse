<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * A rendered report, independent of how it is shown (screen, XLSX, PDF).
 *
 * Every output format is built from this one object, so a screen and its export
 * can never disagree about a number.
 */
final class ReportResult
{
    /**
     * @param  array<string, string>  $columns  key => heading, in display order
     * @param  array<int, array<string, scalar|null>>  $rows
     * @param  array<string, scalar|null>  $summary  label => value, shown above the table
     * @param  array<int, string>  $notes  definitions shown under the table
     * @param  array<int, string>  $numeric  column keys that are right-aligned numbers
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $from,
        public readonly string $to,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly array $notes = [],
        public readonly array $numeric = [],
        public readonly ?string $scopeLabel = null,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
