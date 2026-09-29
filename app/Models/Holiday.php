<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day on the institute's holiday calendar — SCHEMA.md section 13.
 *
 * `year` is always derived from `date` on save, so the two can never disagree.
 */
class Holiday extends Model
{
    public const TYPES = [
        'PUBLIC' => 'Gazetted (public)',
        'RESTRICTED' => 'Restricted',
        'LOCAL' => 'Local',
    ];

    protected $fillable = ['date', 'name', 'type'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Holiday $h): void {
            $h->year = (int) $h->date->format('Y');
        });
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
