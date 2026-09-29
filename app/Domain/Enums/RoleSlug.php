<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Typed reference to the four seeded role slugs.
 *
 * Roles live in the `roles` table (PLAN.md decision 4) so an admin can manage
 * them without a deploy. This enum is NOT the source of truth for the role list;
 * it exists so application code can refer to the four roles the workflow depends
 * on without scattering magic strings like 'adg' through controllers and tests.
 *
 * Adding a fifth role is a database operation. Adding a fifth *workflow stage*
 * would require changing the state machine, which is the point: the enum marks
 * exactly which roles carry workflow meaning.
 */
enum RoleSlug: string
{
    case USER = 'user';
    case MANAGER = 'manager';
    case ADG = 'adg';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'User (Employee)',
            self::MANAGER => 'Manager',
            self::ADG => 'ADG / Approval Authority',
            self::ADMIN => 'Administrator',
        };
    }

    /**
     * Short label for the header and navigation rail.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::USER => 'Employee',
            self::MANAGER => 'Manager',
            self::ADG => 'ADG',
            self::ADMIN => 'Admin',
        };
    }

    /**
     * Where each role lands after login. Roles have disjoint work queues, so
     * sending everyone to a single dashboard would mean an extra click for three
     * of the four roles.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::USER => 'my.requests.index',
            self::MANAGER => 'manager.requests.index',
            self::ADG => 'adg.requests.index',
            self::ADMIN => 'dashboard',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
