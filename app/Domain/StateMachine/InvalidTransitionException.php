<?php

declare(strict_types=1);

namespace App\Domain\StateMachine;

use App\Domain\Enums\RequestAction;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use DomainException;

/**
 * Thrown whenever a caller attempts a transition the workflow does not permit.
 *
 * Messages name the state, action and role involved. A bare "invalid transition"
 * is nearly useless when debugging an approval chain, and these messages surface
 * in the audit trail when an attempt is refused.
 */
class InvalidTransitionException extends DomainException
{
    public static function noSuchTransition(RequestStatus $from, RequestAction $action): self
    {
        return new self(sprintf(
            'Action "%s" is not permitted while the request is in state "%s".',
            $action->value,
            $from->value,
        ));
    }

    public static function terminalState(RequestStatus $from, RequestAction $action): self
    {
        return new self(sprintf(
            'The request is in terminal state "%s" and admits no further changes, '
            .'so action "%s" was refused.',
            $from->value,
            $action->value,
        ));
    }

    public static function wrongActor(
        RequestStatus $from,
        RequestAction $action,
        RoleSlug $actor,
        array $permitted,
    ): self {
        return new self(sprintf(
            'Role "%s" may not perform action "%s" on a request in state "%s". Permitted roles: %s.',
            $actor->value,
            $action->value,
            $from->value,
            implode(', ', array_map(fn (RoleSlug $r) => $r->value, $permitted)),
        ));
    }

    public static function illegalTarget(
        RequestStatus $from,
        RequestAction $action,
        RequestStatus $to,
        array $permitted,
    ): self {
        return new self(sprintf(
            'Action "%s" from state "%s" cannot result in state "%s". Permitted results: %s.',
            $action->value,
            $from->value,
            $to->value,
            implode(', ', array_map(fn (RequestStatus $s) => $s->value, $permitted)),
        ));
    }

    public static function missingRemarks(RequestAction $action): self
    {
        return new self(sprintf(
            'Action "%s" requires written remarks explaining the decision.',
            $action->value,
        ));
    }
}
