<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * "The year's holidays" — TESTING.md section 4 seed data.
 *
 * Fixed-date national holidays only. Festival dates move each year and are
 * notified by the Government of India, so the Administration adds those from the
 * holiday calendar screen rather than trusting a hard-coded guess.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->format('Y');

        foreach ([
            ['01-26', 'Republic Day'],
            ['08-15', 'Independence Day'],
            ['10-02', "Mahatma Gandhi's Birthday"],
            ['12-25', 'Christmas Day'],
        ] as [$md, $name]) {
            Holiday::updateOrCreate(
                ['date' => "{$year}-{$md}"],
                ['name' => $name, 'type' => 'PUBLIC'],
            );
        }
    }
}
