<?php

declare(strict_types=1);

namespace App\Domain\StateMachine;

use App\Domain\Enums\RequestAction;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;

/**
 * One row of the WORKFLOW.md section 2 transition table.
 *
 * Immutable and framework-free: this class knows nothing about Eloquent, HTTP or
 * the container, which is what lets the whole state machine be unit-tested with
 * no database.
 */
final class TransitionRule
{
    /**
     * @param  string  $id  the spec identifier, e.g. "T6" — kept so a failing
     *                      test points straight at a row in WORKFLOW.md
     * @param  array<int, RequestStatus>  $from  states this action may start from
     * @param  array<int, RequestStatus>  $to  legal resulting states
     * @param  array<int, RoleSlug>  $actors  roles permitted to perform it
     * @param  bool  $ownerOnly  actor must additionally be the requester
     */
    public function __construct(
        public readonly string $id,
        public readonly RequestAction $action,
        public readonly array $from,
        public readonly array $to,
        public readonly array $actors,
        public readonly bool $ownerOnly = false,
        public readonly string $note = '',
    ) {}

    public function appliesTo(RequestStatus $from, RequestAction $action): bool
    {
        return $this->action === $action && in_array($from, $this->from, true);
    }

    public function permitsActor(RoleSlug $actor): bool
    {
        return in_array($actor, $this->actors, true);
    }

    public function permitsTarget(RequestStatus $to): bool
    {
        return in_array($to, $this->to, true);
    }

    /**
     * When an action has exactly one legal outcome the caller need not specify
     * it. ALLOT is the only action with a genuine choice (partial vs complete).
     */
    public function soleTarget(): ?RequestStatus
    {
        return count($this->to) === 1 ? $this->to[0] : null;
    }
}
