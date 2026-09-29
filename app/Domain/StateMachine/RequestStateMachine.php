<?php

declare(strict_types=1);

namespace App\Domain\StateMachine;

use App\Domain\Enums\RequestAction as A;
use App\Domain\Enums\RequestStatus as S;
use App\Domain\Enums\RoleSlug as R;

/**
 * The workflow engine. Implements WORKFLOW.md section 2 literally.
 *
 * Anything not in the table below is forbidden — the machine is closed by
 * default. That is the whole point: a government approval chain must not be
 * bypassable by an unanticipated code path, so the default answer to "may I
 * make this transition" is no.
 *
 * Framework-free by design (no Eloquent, no facades, no container), so the
 * entire approval workflow is unit-testable without touching a database.
 */
final class RequestStateMachine
{
    /** @var array<int, TransitionRule>|null */
    private static ?array $rules = null;

    /**
     * The transition table. Order matches WORKFLOW.md so the two can be diffed
     * by eye during review.
     *
     * @return array<int, TransitionRule>
     */
    public static function rules(): array
    {
        return self::$rules ??= [

            // T1 — employee submits a drafted request.
            new TransitionRule('T1', A::SUBMIT,
                from: [S::DRAFT],
                to: [S::PENDING_MANAGER],
                actors: [R::USER, R::MANAGER, R::ADG, R::ADMIN],
                ownerOnly: true,
                note: 'Any role may submit a request for themselves; a Manager also travels.'),

            // T2 — Manager approves. This is the only approval required: the
            // request goes straight to the Administration for room allotment.
            // (The former ADG stage has been removed from the normal flow.)
            new TransitionRule('T2', A::MANAGER_APPROVE,
                from: [S::PENDING_MANAGER],
                to: [S::PENDING_ALLOTMENT],
                actors: [R::MANAGER]),

            // T3 — Manager rejects. Terminal.
            new TransitionRule('T3', A::MANAGER_REJECT,
                from: [S::PENDING_MANAGER],
                to: [S::REJECTED_MANAGER],
                actors: [R::MANAGER]),

            // T4 — Manager asks the applicant for more information.
            new TransitionRule('T4', A::MANAGER_REQUEST_INFO,
                from: [S::PENDING_MANAGER],
                to: [S::MORE_INFO_MANAGER],
                actors: [R::MANAGER]),

            // T5 — applicant supplies the information. THE RETURN PATH.
            // Omitting this was a real defect in the first draft of the spec:
            // without it every more-info request dead-ends forever.
            new TransitionRule('T5', A::RESUBMIT,
                from: [S::MORE_INFO_MANAGER],
                to: [S::PENDING_MANAGER],
                actors: [R::USER, R::MANAGER, R::ADG, R::ADMIN],
                ownerOnly: true),

            // T6 — ADG approves. Only now may an admin look at availability.
            new TransitionRule('T6', A::ADG_APPROVE,
                from: [S::PENDING_ADG],
                to: [S::PENDING_ALLOTMENT],
                actors: [R::ADG]),

            // T7 — ADG rejects. Terminal.
            new TransitionRule('T7', A::ADG_REJECT,
                from: [S::PENDING_ADG],
                to: [S::REJECTED_ADG],
                actors: [R::ADG]),

            // NOTE: there is deliberately NO rule allowing the ADG to request
            // more information (PLAN.md decision 5). Its absence here is the
            // third of three enforcement layers, alongside the missing route and
            // the missing permission.

            // T8 / T9 / T11 — admin allots rooms. One action, two possible
            // outcomes depending on how many of `rooms_needed` were filled.
            new TransitionRule('T8/T9/T11', A::ALLOT,
                from: [S::PENDING_ALLOTMENT, S::PARTIALLY_ALLOTTED],
                to: [S::PARTIALLY_ALLOTTED, S::ALLOTTED],
                actors: [R::ADMIN],
                note: 'Target resolved by the caller from rooms allotted vs rooms needed.'),

            // T10 — approved, but nothing free.
            new TransitionRule('T10', A::MARK_NO_ROOM,
                from: [S::PENDING_ALLOTMENT],
                to: [S::NO_ROOM_AVAILABLE],
                actors: [R::ADMIN]),

            // T20 — waitlist retry: look again later.
            new TransitionRule('T20', A::RECHECK_AVAILABILITY,
                from: [S::NO_ROOM_AVAILABLE],
                to: [S::PENDING_ALLOTMENT],
                actors: [R::ADMIN],
                note: 'The single legal exit from the NO_ROOM_AVAILABLE terminal state.'),

            // T21 — rooms the Manager held at review become the allotment as
            // soon as the Manager approves (the approval and confirmation happen
            // together, since Manager approval is now the only approval).
            // PLAN.md decision 10.
            new TransitionRule('T21', A::CONFIRM_ALLOTMENT,
                from: [S::PENDING_ALLOTMENT],
                to: [S::PARTIALLY_ALLOTTED, S::ALLOTTED],
                actors: [R::MANAGER, R::ADG],
                note: 'Target resolved from rooms held vs rooms needed, as for ALLOT.'),

            // T12 — guests arrive.
            new TransitionRule('T12', A::CHECK_IN,
                from: [S::ALLOTTED],
                to: [S::CHECKED_IN],
                actors: [R::ADMIN]),

            // T13 — cancel an allotted booking before arrival, releasing rooms.
            new TransitionRule('T13', A::CANCEL,
                from: [S::ALLOTTED, S::PARTIALLY_ALLOTTED],
                to: [S::CANCELLED],
                actors: [R::USER, R::MANAGER, R::ADG, R::ADMIN],
                note: 'Owner may withdraw; an admin may cancel on their behalf.'),

            // T19 — withdraw while still in the approval chain.
            new TransitionRule('T19', A::CANCEL,
                from: [S::DRAFT, S::PENDING_MANAGER, S::MORE_INFO_MANAGER, S::PENDING_ADG],
                to: [S::CANCELLED],
                actors: [R::USER, R::MANAGER, R::ADG, R::ADMIN],
                ownerOnly: true),

            // T14 — normal checkout.
            new TransitionRule('T14', A::CHECK_OUT,
                from: [S::CHECKED_IN],
                to: [S::CHECKED_OUT],
                actors: [R::ADMIN]),

            // T15 — left early; rooms free immediately.
            new TransitionRule('T15', A::EARLY_CHECKOUT,
                from: [S::CHECKED_IN],
                to: [S::EARLY_CHECKOUT],
                actors: [R::ADMIN]),

            // T16 — guest asks to stay longer.
            new TransitionRule('T16', A::REQUEST_EXTENSION,
                from: [S::CHECKED_IN],
                to: [S::EXTENSION_REQUESTED],
                actors: [R::USER, R::MANAGER, R::ADG, R::ADMIN],
                ownerOnly: true),

            // T17 — extension granted; same target as T18 but different effects.
            new TransitionRule('T17', A::APPROVE_EXTENSION,
                from: [S::EXTENSION_REQUESTED],
                to: [S::CHECKED_IN],
                actors: [R::ADMIN],
                note: 'Only after re-verifying the extra nights on the same rooms.'),

            // T18 — extension denied; original dates stand.
            new TransitionRule('T18', A::DENY_EXTENSION,
                from: [S::EXTENSION_REQUESTED],
                to: [S::CHECKED_IN],
                actors: [R::ADMIN]),
        ];
    }

    /**
     * Validate a transition and return the resulting state.
     *
     * @param  S  $from  current state
     * @param  A  $action  action being attempted
     * @param  R  $actor  role of the acting user
     * @param  S|null  $to  required only for actions with more than one outcome
     * @param  bool  $isOwner  whether the actor is the requester
     * @param  string|null  $remarks  reason text, for actions that require one
     *
     * @throws InvalidTransitionException
     */
    public static function assert(
        S $from,
        A $action,
        R $actor,
        ?S $to = null,
        bool $isOwner = false,
        ?string $remarks = null,
    ): S {
        $rule = self::findRule($from, $action);

        if ($rule === null) {
            // A terminal state gets a clearer message than "no such transition",
            // because it is the most common legitimate refusal.
            throw $from->isTerminal() && $action !== A::RECHECK_AVAILABILITY
                ? InvalidTransitionException::terminalState($from, $action)
                : InvalidTransitionException::noSuchTransition($from, $action);
        }

        if (! $rule->permitsActor($actor)) {
            throw InvalidTransitionException::wrongActor($from, $action, $actor, $rule->actors);
        }

        // Owner-restricted actions: an administrator may act on any request, but
        // nobody else may submit, resubmit or extend on another person's behalf.
        if ($rule->ownerOnly && ! $isOwner && $actor !== R::ADMIN) {
            throw InvalidTransitionException::wrongActor($from, $action, $actor, [R::ADMIN]);
        }

        if ($action->requiresRemarks() && trim((string) $remarks) === '') {
            throw InvalidTransitionException::missingRemarks($action);
        }

        $target = $to ?? $rule->soleTarget();

        if ($target === null) {
            throw InvalidTransitionException::illegalTarget($from, $action, $from, $rule->to);
        }

        if (! $rule->permitsTarget($target)) {
            throw InvalidTransitionException::illegalTarget($from, $action, $target, $rule->to);
        }

        return $target;
    }

    /**
     * Non-throwing variant, for deciding whether to render a button.
     *
     * Rendering is the only legitimate use. Never rely on this alone to protect
     * an action — the service layer must still call assert().
     */
    public static function can(
        S $from,
        A $action,
        R $actor,
        ?S $to = null,
        bool $isOwner = false,
    ): bool {
        try {
            // Remarks are supplied here so that a missing-reason failure does not
            // hide a button the actor is genuinely allowed to use.
            self::assert($from, $action, $actor, $to, $isOwner, 'probe');

            return true;
        } catch (InvalidTransitionException) {
            return false;
        }
    }

    /**
     * Every action the given role may currently perform. Drives the action bar
     * on the request detail screens.
     *
     * @return array<int, A>
     */
    public static function availableActions(S $from, R $actor, bool $isOwner = false): array
    {
        return array_values(array_filter(
            A::cases(),
            fn (A $action) => self::can($from, $action, $actor, null, $isOwner)
                || self::canWithAnyTarget($from, $action, $actor, $isOwner),
        ));
    }

    private static function canWithAnyTarget(S $from, A $action, R $actor, bool $isOwner): bool
    {
        $rule = self::findRule($from, $action);

        if ($rule === null) {
            return false;
        }

        foreach ($rule->to as $target) {
            if (self::can($from, $action, $actor, $target, $isOwner)) {
                return true;
            }
        }

        return false;
    }

    private static function findRule(S $from, A $action): ?TransitionRule
    {
        foreach (self::rules() as $rule) {
            if ($rule->appliesTo($from, $action)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Resolve the ALLOT outcome from counts — encodes T8 versus T9.
     */
    public static function allotmentTarget(int $roomsAllotted, int $roomsNeeded): S
    {
        if ($roomsAllotted <= 0) {
            throw new InvalidTransitionException(
                'Allotting zero rooms is not a transition; mark the request as no room available instead.'
            );
        }

        return $roomsAllotted >= $roomsNeeded ? S::ALLOTTED : S::PARTIALLY_ALLOTTED;
    }
}
