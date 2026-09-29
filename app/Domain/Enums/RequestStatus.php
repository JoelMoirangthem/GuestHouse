<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * The 15 states of a booking request — WORKFLOW.md section 1.
 *
 * This enum is the source of truth for status. Unlike room types or roles, the
 * state set is NOT admin-editable: adding a state means adding transitions,
 * guards, notifications and audit semantics, which is a code change by nature.
 * That is why this is an enum while `room_types` is a table.
 */
enum RequestStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_MANAGER = 'PENDING_MANAGER';
    case MORE_INFO_MANAGER = 'MORE_INFO_MANAGER';
    case REJECTED_MANAGER = 'REJECTED_MANAGER';
    case PENDING_ADG = 'PENDING_ADG';
    case REJECTED_ADG = 'REJECTED_ADG';
    case PENDING_ALLOTMENT = 'PENDING_ALLOTMENT';
    case NO_ROOM_AVAILABLE = 'NO_ROOM_AVAILABLE';
    case PARTIALLY_ALLOTTED = 'PARTIALLY_ALLOTTED';
    case ALLOTTED = 'ALLOTTED';
    case CHECKED_IN = 'CHECKED_IN';
    case CHECKED_OUT = 'CHECKED_OUT';
    case EARLY_CHECKOUT = 'EARLY_CHECKOUT';
    case CANCELLED = 'CANCELLED';
    case EXTENSION_REQUESTED = 'EXTENSION_REQUESTED';

    /**
     * Badge text shown to users — SCREENS.md section 4.
     *
     * Deliberately phrased from the applicant's point of view ("Staying", not
     * "CHECKED_IN"), because these labels appear in the employee's own list.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_MANAGER => 'Pending at Manager',
            self::MORE_INFO_MANAGER => 'Information Required',
            self::REJECTED_MANAGER => 'Rejected by Manager',
            self::PENDING_ADG => 'Pending at ADG',
            self::REJECTED_ADG => 'Rejected by ADG',
            self::PENDING_ALLOTMENT => 'Awaiting Allotment',
            self::NO_ROOM_AVAILABLE => 'No Room Available',
            self::PARTIALLY_ALLOTTED => 'Partially Allotted',
            self::ALLOTTED => 'Allotted',
            self::CHECKED_IN => 'Staying',
            self::CHECKED_OUT => 'Completed',
            self::EARLY_CHECKOUT => 'Completed (Early)',
            self::CANCELLED => 'Cancelled',
            self::EXTENSION_REQUESTED => 'Extension Requested',
        };
    }

    /**
     * Tailwind utility classes for the status pill. Every state pairs a colour
     * with its label text and a dot, so colour is never the sole carrier of
     * meaning (WCAG 1.4.1).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-[--color-neutral-bg] border-[--color-line-strong] text-[--color-neutral]',
            self::PENDING_MANAGER => 'bg-[--color-pending-bg] border-amber-200 text-[--color-pending]',
            self::MORE_INFO_MANAGER => 'bg-[--color-attention-bg] border-orange-200 text-[--color-attention]',
            self::REJECTED_MANAGER,
            self::REJECTED_ADG => 'bg-[--color-danger-bg] border-red-200 text-[--color-danger]',
            self::PENDING_ADG => 'bg-[--color-info-bg] border-blue-200 text-[--color-info]',
            self::PENDING_ALLOTMENT => 'bg-[--color-await-bg] border-teal-200 text-[--color-await]',
            self::NO_ROOM_AVAILABLE => 'bg-[--color-danger-bg] border-red-300 text-red-800',
            self::PARTIALLY_ALLOTTED => 'bg-[--color-success-bg] border-green-200 text-green-700',
            self::ALLOTTED => 'bg-[--color-success-bg] border-green-300 text-[--color-success]',
            self::CHECKED_IN => 'bg-[--color-success-bg] border-green-400 text-green-800',
            self::CHECKED_OUT,
            self::EARLY_CHECKOUT => 'bg-[--color-neutral-bg] border-[--color-line-strong] text-[--color-neutral]',
            self::CANCELLED => 'bg-[--color-neutral-bg] border-[--color-line-strong] text-zinc-700',
            self::EXTENSION_REQUESTED => 'bg-[--color-extend-bg] border-violet-200 text-[--color-extend]',
        };
    }

    /**
     * Terminal states admit no further transitions — WORKFLOW.md section 1.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REJECTED_MANAGER,
            self::REJECTED_ADG,
            self::NO_ROOM_AVAILABLE,
            self::CHECKED_OUT,
            self::EARLY_CHECKOUT,
            self::CANCELLED,
        ], true);
    }

    /**
     * The only two states in which an administrator may run an availability
     * search or allot a room. Core Rule 1 lives here.
     */
    public function allowsAvailabilityCheck(): bool
    {
        return in_array($this, [
            self::PENDING_ALLOTMENT,
            self::PARTIALLY_ALLOTTED,
        ], true);
    }

    /** Request is somewhere in the approval chain and not yet decided. */
    public function isAwaitingApproval(): bool
    {
        return in_array($this, [
            self::PENDING_MANAGER,
            self::MORE_INFO_MANAGER,
            self::PENDING_ADG,
        ], true);
    }

    /** At least one room is held for this request. */
    public function holdsRooms(): bool
    {
        return in_array($this, [
            self::PARTIALLY_ALLOTTED,
            self::ALLOTTED,
            self::CHECKED_IN,
            self::EXTENSION_REQUESTED,
        ], true);
    }

    /**
     * States in which an allotment letter may be issued. Every room must be in
     * hand: a partly allotted request cannot check in (PLAN.md decision 9), so a
     * letter for it would promise what the desk will refuse.
     */
    public function issuesLetter(): bool
    {
        return in_array($this, [
            self::ALLOTTED,
            self::CHECKED_IN,
            self::EXTENSION_REQUESTED,
        ], true);
    }

    /**
     * States that count toward the "Total Requests" dashboard tile —
     * REPORTS.md section 1 excludes drafts.
     */
    public function countsAsSubmitted(): bool
    {
        return $this !== self::DRAFT;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<int, self> */
    public static function terminal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->isTerminal()));
    }
}
