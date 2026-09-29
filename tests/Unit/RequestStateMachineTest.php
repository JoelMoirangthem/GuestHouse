<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\RequestAction as A;
use App\Domain\Enums\RequestStatus as S;
use App\Domain\Enums\RoleSlug as R;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Domain\StateMachine\RequestStateMachine as M;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TESTING.md — RequestStateMachineTest, the Phase 2 gate.
 *
 * "Every one of the 20 transitions succeeds under valid guards. Every forbidden
 * transition throws." Extends PHPUnit's TestCase rather than Laravel's, which
 * proves the state machine really is framework-free — it boots no application.
 */
class RequestStateMachineTest extends TestCase
{
    // ---------------------------------------------------------------- T1 - T20

    /**
     * Every legal transition, keyed by its WORKFLOW.md identifier.
     *
     * @return array<string, array{0: S, 1: A, 2: R, 3: S, 4: bool}>
     */
    public static function legalTransitions(): array
    {
        return [
            'T1  submit' => [S::DRAFT,               A::SUBMIT,               R::USER,    S::PENDING_MANAGER,   true],
            'T2  manager approve' => [S::PENDING_MANAGER,     A::MANAGER_APPROVE,      R::MANAGER, S::PENDING_ALLOTMENT, false],
            'T3  manager reject' => [S::PENDING_MANAGER,     A::MANAGER_REJECT,       R::MANAGER, S::REJECTED_MANAGER,  false],
            'T4  manager more info' => [S::PENDING_MANAGER,     A::MANAGER_REQUEST_INFO, R::MANAGER, S::MORE_INFO_MANAGER, false],
            'T5  resubmit' => [S::MORE_INFO_MANAGER,   A::RESUBMIT,             R::USER,    S::PENDING_MANAGER,   true],
            'T6  adg approve' => [S::PENDING_ADG,         A::ADG_APPROVE,          R::ADG,     S::PENDING_ALLOTMENT, false],
            'T7  adg reject' => [S::PENDING_ADG,         A::ADG_REJECT,           R::ADG,     S::REJECTED_ADG,      false],
            'T8  allot partially' => [S::PENDING_ALLOTMENT,   A::ALLOT,                R::ADMIN,   S::PARTIALLY_ALLOTTED, false],
            'T9  allot fully' => [S::PENDING_ALLOTMENT,   A::ALLOT,                R::ADMIN,   S::ALLOTTED,          false],
            'T10 no room' => [S::PENDING_ALLOTMENT,   A::MARK_NO_ROOM,         R::ADMIN,   S::NO_ROOM_AVAILABLE, false],
            'T11 allot remaining' => [S::PARTIALLY_ALLOTTED,  A::ALLOT,                R::ADMIN,   S::ALLOTTED,          false],
            'T12 check in' => [S::ALLOTTED,            A::CHECK_IN,             R::ADMIN,   S::CHECKED_IN,        false],
            'T13 cancel allotted' => [S::ALLOTTED,            A::CANCEL,               R::ADMIN,   S::CANCELLED,         false],
            'T14 check out' => [S::CHECKED_IN,          A::CHECK_OUT,            R::ADMIN,   S::CHECKED_OUT,       false],
            'T15 early checkout' => [S::CHECKED_IN,          A::EARLY_CHECKOUT,       R::ADMIN,   S::EARLY_CHECKOUT,    false],
            'T16 request extension' => [S::CHECKED_IN,          A::REQUEST_EXTENSION,    R::USER,    S::EXTENSION_REQUESTED, true],
            'T17 approve extension' => [S::EXTENSION_REQUESTED, A::APPROVE_EXTENSION,    R::ADMIN,   S::CHECKED_IN,        false],
            'T18 deny extension' => [S::EXTENSION_REQUESTED, A::DENY_EXTENSION,       R::ADMIN,   S::CHECKED_IN,        false],
            'T19 withdraw at manager' => [S::PENDING_MANAGER,     A::CANCEL,               R::USER,    S::CANCELLED,         true],
            'T19 withdraw at more info' => [S::MORE_INFO_MANAGER,   A::CANCEL,               R::USER,    S::CANCELLED,         true],
            'T19 withdraw at adg' => [S::PENDING_ADG,         A::CANCEL,               R::USER,    S::CANCELLED,         true],
            'T20 recheck availability' => [S::NO_ROOM_AVAILABLE,   A::RECHECK_AVAILABILITY, R::ADMIN,   S::PENDING_ALLOTMENT, false],
        ];
    }

    #[Test]
    #[DataProvider('legalTransitions')]
    public function a_legal_transition_is_permitted_and_returns_the_expected_state(
        S $from, A $action, R $actor, S $expected, bool $isOwner
    ): void {
        $result = M::assert($from, $action, $actor, $expected, $isOwner, 'Reason supplied for the record.');

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function the_transition_table_covers_every_numbered_row_in_the_specification(): void
    {
        // Guards against a rule being silently deleted during a refactor.
        $ids = array_map(fn ($r) => $r->id, M::rules());

        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8/T9/T11', 'T10',
            'T12', 'T13', 'T14', 'T15', 'T16', 'T17', 'T18', 'T19', 'T20'] as $id) {
            $this->assertContains($id, $ids, "Transition {$id} is missing from the table.");
        }
    }

    // ------------------------------------------------- DECISION 5: ADG limits

    #[Test]
    public function the_adg_cannot_request_more_information(): void
    {
        // PLAN.md decision 5. This is the state-machine layer of a three-layer
        // guarantee; the route and the permission are the other two.
        $this->expectException(InvalidTransitionException::class);

        M::assert(S::PENDING_ADG, A::MANAGER_REQUEST_INFO, R::ADG);
    }

    #[Test]
    public function no_role_whatsoever_can_request_more_information_at_the_adg_stage(): void
    {
        foreach (R::cases() as $role) {
            $this->assertFalse(
                M::can(S::PENDING_ADG, A::MANAGER_REQUEST_INFO, $role, null, true),
                "Role {$role->value} must not be able to request more info at the ADG stage."
            );
        }
    }

    #[Test]
    public function the_adg_has_exactly_two_available_actions_on_a_pending_request(): void
    {
        $actions = M::availableActions(S::PENDING_ADG, R::ADG);

        $this->assertEqualsCanonicalizing(
            [A::ADG_APPROVE, A::ADG_REJECT],
            $actions,
            'The ADG must be able to approve or reject, and nothing else.'
        );
    }

    // ------------------------------------------------- stage skipping

    #[Test]
    public function the_manager_stage_cannot_skip_straight_to_allotment(): void
    {
        $this->expectException(InvalidTransitionException::class);

        M::assert(S::PENDING_MANAGER, A::ADG_APPROVE, R::MANAGER, S::PENDING_ALLOTMENT);
    }

    #[Test]
    public function the_adg_stage_cannot_skip_the_availability_step_and_allot_directly(): void
    {
        $this->expectException(InvalidTransitionException::class);

        M::assert(S::PENDING_ADG, A::ALLOT, R::ADMIN, S::ALLOTTED);
    }

    #[Test]
    public function rooms_cannot_be_allotted_before_the_adg_has_approved(): void
    {
        foreach ([S::DRAFT, S::PENDING_MANAGER, S::MORE_INFO_MANAGER, S::PENDING_ADG] as $state) {
            $this->assertFalse(
                M::can($state, A::ALLOT, R::ADMIN, S::ALLOTTED),
                "Allotment must be impossible from {$state->value} — Core Rule 1."
            );
        }
    }

    #[Test]
    public function only_two_states_permit_an_availability_check(): void
    {
        $allowed = array_values(array_filter(S::cases(), fn (S $s) => $s->allowsAvailabilityCheck()));

        $this->assertEqualsCanonicalizing([S::PENDING_ALLOTMENT, S::PARTIALLY_ALLOTTED], $allowed);
    }

    // ------------------------------------------------- terminal states

    /** @return array<string, array{0: S}> */
    public static function terminalStates(): array
    {
        return [
            'rejected by manager' => [S::REJECTED_MANAGER],
            'rejected by adg' => [S::REJECTED_ADG],
            'checked out' => [S::CHECKED_OUT],
            'early checkout' => [S::EARLY_CHECKOUT],
            'cancelled' => [S::CANCELLED],
        ];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function no_action_can_leave_a_terminal_state(S $terminal): void
    {
        foreach (A::cases() as $action) {
            foreach (R::cases() as $role) {
                $this->assertFalse(
                    M::can($terminal, $action, $role, null, true),
                    "Action {$action->value} must be refused from terminal state {$terminal->value}."
                );
            }
        }
    }

    #[Test]
    public function no_room_available_is_terminal_except_for_the_admin_recheck(): void
    {
        // The one deliberate exception: an admin may look again later (T20).
        $this->assertTrue(S::NO_ROOM_AVAILABLE->isTerminal());

        $actions = M::availableActions(S::NO_ROOM_AVAILABLE, R::ADMIN);
        $this->assertSame([A::RECHECK_AVAILABILITY], $actions);

        // and nobody else may do even that
        foreach ([R::USER, R::MANAGER, R::ADG] as $role) {
            $this->assertFalse(M::can(S::NO_ROOM_AVAILABLE, A::RECHECK_AVAILABILITY, $role, null, true));
        }
    }

    // ------------------------------------------------- actor restrictions

    #[Test]
    public function a_regular_user_cannot_approve_anything(): void
    {
        foreach ([A::MANAGER_APPROVE, A::ADG_APPROVE, A::MANAGER_REJECT, A::ADG_REJECT] as $action) {
            $this->assertFalse(M::can(S::PENDING_MANAGER, $action, R::USER, null, true));
            $this->assertFalse(M::can(S::PENDING_ADG, $action, R::USER, null, true));
        }
    }

    #[Test]
    public function a_manager_cannot_perform_the_adg_approval(): void
    {
        $this->assertFalse(M::can(S::PENDING_ADG, A::ADG_APPROVE, R::MANAGER));
    }

    #[Test]
    public function an_adg_cannot_perform_the_manager_approval(): void
    {
        $this->assertFalse(M::can(S::PENDING_MANAGER, A::MANAGER_APPROVE, R::ADG));
    }

    #[Test]
    public function only_an_administrator_may_allot_rooms_or_manage_a_stay(): void
    {
        foreach ([R::USER, R::MANAGER, R::ADG] as $role) {
            $this->assertFalse(M::can(S::PENDING_ALLOTMENT, A::ALLOT, $role, S::ALLOTTED));
            $this->assertFalse(M::can(S::PENDING_ALLOTMENT, A::MARK_NO_ROOM, $role));
            $this->assertFalse(M::can(S::ALLOTTED, A::CHECK_IN, $role));
            $this->assertFalse(M::can(S::CHECKED_IN, A::CHECK_OUT, $role));
            $this->assertFalse(M::can(S::EXTENSION_REQUESTED, A::APPROVE_EXTENSION, $role));
        }
    }

    #[Test]
    public function a_user_cannot_submit_or_resubmit_someone_elses_request(): void
    {
        $this->assertFalse(M::can(S::DRAFT, A::SUBMIT, R::USER, null, isOwner: false));
        $this->assertFalse(M::can(S::MORE_INFO_MANAGER, A::RESUBMIT, R::USER, null, isOwner: false));
        $this->assertFalse(M::can(S::CHECKED_IN, A::REQUEST_EXTENSION, R::USER, null, isOwner: false));
    }

    #[Test]
    public function the_more_info_loop_returns_the_request_to_the_manager(): void
    {
        // T4 then T5: the return path. Its absence was a genuine defect in the
        // first draft of the specification.
        $afterAsk = M::assert(S::PENDING_MANAGER, A::MANAGER_REQUEST_INFO, R::MANAGER,
            remarks: 'Please attach a legible ID proof.');
        $this->assertSame(S::MORE_INFO_MANAGER, $afterAsk);

        $afterResubmit = M::assert($afterAsk, A::RESUBMIT, R::USER, isOwner: true);
        $this->assertSame(S::PENDING_MANAGER, $afterResubmit);
    }

    // ------------------------------------------------- remarks requirement

    /** @return array<string, array{0: S, 1: A, 2: R}> */
    public static function actionsRequiringRemarks(): array
    {
        return [
            'manager reject' => [S::PENDING_MANAGER, A::MANAGER_REJECT, R::MANAGER],
            'manager more info' => [S::PENDING_MANAGER, A::MANAGER_REQUEST_INFO, R::MANAGER],
            'adg reject' => [S::PENDING_ADG, A::ADG_REJECT, R::ADG],
            'deny extension' => [S::EXTENSION_REQUESTED, A::DENY_EXTENSION, R::ADMIN],
            'mark no room' => [S::PENDING_ALLOTMENT, A::MARK_NO_ROOM, R::ADMIN],
        ];
    }

    #[Test]
    #[DataProvider('actionsRequiringRemarks')]
    public function a_decision_against_the_applicant_requires_written_remarks(S $from, A $action, R $actor): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessageMatches('/requires written remarks/');

        M::assert($from, $action, $actor, remarks: '   ');
    }

    #[Test]
    public function an_approval_does_not_require_remarks(): void
    {
        // Manager approval is now the only approval and sends the request straight
        // to the Administration for room allotment.
        $this->assertSame(S::PENDING_ALLOTMENT, M::assert(S::PENDING_MANAGER, A::MANAGER_APPROVE, R::MANAGER));
    }

    // ------------------------------------------------- allotment target maths

    /** @return array<string, array{0: int, 1: int, 2: S}> */
    public static function allotmentCounts(): array
    {
        return [
            '1 of 1 -> allotted' => [1, 1, S::ALLOTTED],
            '2 of 3 -> partially allotted' => [2, 3, S::PARTIALLY_ALLOTTED],
            '3 of 3 -> allotted' => [3, 3, S::ALLOTTED],
            '4 of 3 -> allotted (override)' => [4, 3, S::ALLOTTED],
            '1 of 5 -> partially allotted' => [1, 5, S::PARTIALLY_ALLOTTED],
        ];
    }

    #[Test]
    #[DataProvider('allotmentCounts')]
    public function the_allotment_outcome_follows_rooms_allotted_versus_needed(
        int $allotted, int $needed, S $expected
    ): void {
        $this->assertSame($expected, M::allotmentTarget($allotted, $needed));
    }

    #[Test]
    public function allotting_zero_rooms_is_not_a_transition(): void
    {
        $this->expectException(InvalidTransitionException::class);

        M::allotmentTarget(0, 3);
    }

    // ------------------------------------------------- illegal targets

    #[Test]
    public function an_action_cannot_be_redirected_to_an_arbitrary_state(): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessageMatches('/cannot result in state/');

        // Manager approval must land on PENDING_ADG, never straight to ALLOTTED.
        M::assert(S::PENDING_MANAGER, A::MANAGER_APPROVE, R::MANAGER, S::ALLOTTED);
    }

    #[Test]
    public function every_state_is_reachable_or_is_the_start_state(): void
    {
        $reachable = [S::DRAFT];

        foreach (M::rules() as $rule) {
            foreach ($rule->to as $target) {
                $reachable[] = $target;
            }
        }

        // PENDING_ADG is a retired state: the second-level ADG approval was
        // removed from the workflow, so Manager approval now goes straight to
        // PENDING_ALLOTMENT. The ADG's own rules (T6/T7) are kept for safety but
        // nothing transitions into PENDING_ADG any longer, so it is deliberately
        // no longer reachable.
        $retired = [S::PENDING_ADG];

        foreach (S::cases() as $state) {
            if (in_array($state, $retired, true)) {
                $this->assertNotContains($state, $reachable,
                    "State {$state->value} is retired and must no longer be reachable.");

                continue;
            }

            $this->assertContains($state, $reachable, "State {$state->value} is unreachable.");
        }
    }

    #[Test]
    public function the_enum_reports_exactly_six_terminal_states(): void
    {
        $this->assertCount(6, S::terminal());
        $this->assertCount(15, S::cases());
    }
}
