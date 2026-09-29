<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * The three visit purposes from the infographic — every request must declare one.
 *
 * Purpose is an enum rather than a table because it is fixed by institutional
 * policy and drives conditional validation, tariff selection and the
 * purpose-wise report. Adding a fourth purpose is a policy change with code
 * consequences, not a data entry task.
 */
enum VisitPurpose: string
{
    case TRAINING = 'TRAINING';
    case SELF = 'SELF';
    case GUEST = 'GUEST';

    /** Card title on the request form (SCREENS.md 3.2). */
    public function label(): string
    {
        return match ($this) {
            self::TRAINING => 'Training Purpose',
            self::SELF => 'Self Visit',
            self::GUEST => 'Guest Visit',
        };
    }

    /** The applicant-voice description shown under each card. */
    public function description(): string
    {
        return match ($this) {
            self::TRAINING => 'I am coming for a Training Programme',
            self::SELF => 'I am visiting on Official Work',
            self::GUEST => 'I am a Guest of a NADT Employee',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TRAINING => 'academic',
            self::SELF => 'briefcase',
            self::GUEST => 'users',
        };
    }

    /**
     * Requires the training programme name.
     */
    public function needsTrainingProgramme(): bool
    {
        return $this === self::TRAINING;
    }

    /**
     * Requires a host employee. A guest cannot stay unattributed: someone on the
     * staff must own the invitation, both for accountability and for billing.
     */
    public function needsHost(): bool
    {
        return $this === self::GUEST;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
