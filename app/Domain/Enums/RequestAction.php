<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * The actions that drive a request through the workflow.
 *
 * WHY ACTIONS AND NOT from-to PAIRS
 * ---------------------------------
 * The state machine is keyed on (from-state, action) rather than (from, to).
 * T17 and T18 in WORKFLOW.md both move EXTENSION_REQUESTED back to CHECKED_IN,
 * but one approves the extension and the other denies it — different side
 * effects, different audit entries, different notifications. Keying on the
 * target state alone could not tell them apart.
 *
 * Conversely, one action can have more than one legal target: ALLOT lands on
 * PARTIALLY_ALLOTTED or ALLOTTED depending on how many rooms were assigned
 * (T8 versus T9).
 *
 * 17 actions cover the 20 numbered transitions because CANCEL and ALLOT each
 * appear in the table more than once.
 */
enum RequestAction: string
{
    case SUBMIT = 'submit';                             // T1
    case MANAGER_APPROVE = 'manager_approve';           // T2
    case MANAGER_REJECT = 'manager_reject';             // T3
    case MANAGER_REQUEST_INFO = 'manager_request_info'; // T4
    case RESUBMIT = 'resubmit';                         // T5
    case ADG_APPROVE = 'adg_approve';                   // T6
    case ADG_REJECT = 'adg_reject';                     // T7
    case ALLOT = 'allot';                               // T8, T9, T11
    case MARK_NO_ROOM = 'mark_no_room';                 // T10
    case CHECK_IN = 'check_in';                         // T12
    case CANCEL = 'cancel';                             // T13, T19
    case CHECK_OUT = 'check_out';                       // T14
    case EARLY_CHECKOUT = 'early_checkout';             // T15
    case REQUEST_EXTENSION = 'request_extension';       // T16
    case APPROVE_EXTENSION = 'approve_extension';       // T17
    case DENY_EXTENSION = 'deny_extension';             // T18
    case RECHECK_AVAILABILITY = 'recheck_availability'; // T20
    case CONFIRM_ALLOTMENT = 'confirm_allotment';       // T21

    public function label(): string
    {
        return match ($this) {
            self::SUBMIT => 'Submit request',
            self::MANAGER_APPROVE => 'Approve',
            self::MANAGER_REJECT => 'Reject',
            self::MANAGER_REQUEST_INFO => 'Request more information',
            self::RESUBMIT => 'Resubmit with information',
            self::ADG_APPROVE => 'Approve',
            self::ADG_REJECT => 'Reject',
            self::ALLOT => 'Allot rooms',
            self::MARK_NO_ROOM => 'Mark no room available',
            self::CHECK_IN => 'Check in',
            self::CANCEL => 'Cancel request',
            self::CHECK_OUT => 'Check out',
            self::EARLY_CHECKOUT => 'Early check out',
            self::REQUEST_EXTENSION => 'Request extension',
            self::APPROVE_EXTENSION => 'Approve extension',
            self::DENY_EXTENSION => 'Deny extension',
            self::RECHECK_AVAILABILITY => 'Re-check availability',
            self::CONFIRM_ALLOTMENT => 'Confirm rooms held by the Manager',
        };
    }

    /**
     * The audit action written to `audit_logs` — WORKFLOW.md section 4.
     */
    public function auditAction(): string
    {
        return match ($this) {
            self::SUBMIT => 'SUBMITTED',
            self::MANAGER_APPROVE => 'MANAGER_APPROVED',
            self::MANAGER_REJECT => 'MANAGER_REJECTED',
            self::MANAGER_REQUEST_INFO => 'MORE_INFO_REQUESTED',
            self::RESUBMIT => 'RESUBMITTED',
            self::ADG_APPROVE => 'ADG_APPROVED',
            self::ADG_REJECT => 'ADG_REJECTED',
            self::ALLOT => 'ROOMS_ALLOTTED',
            self::MARK_NO_ROOM => 'NO_ROOM_AVAILABLE',
            self::CHECK_IN => 'CHECKED_IN',
            self::CANCEL => 'CANCELLED',
            self::CHECK_OUT => 'CHECKED_OUT',
            self::EARLY_CHECKOUT => 'EARLY_CHECKOUT',
            self::REQUEST_EXTENSION => 'EXTENSION_REQUESTED',
            self::APPROVE_EXTENSION => 'EXTENSION_APPROVED',
            self::DENY_EXTENSION => 'EXTENSION_DENIED',
            self::RECHECK_AVAILABILITY => 'AVAILABILITY_RECHECK',
            self::CONFIRM_ALLOTMENT => 'ROOMS_CONFIRMED',
        };
    }

    /**
     * Actions that must carry a written reason.
     *
     * Every rejection and every denial is recorded with remarks, because a
     * government applicant is entitled to know why a request failed, and the
     * audit trail is meaningless without it.
     */
    public function requiresRemarks(): bool
    {
        return in_array($this, [
            self::MANAGER_REJECT,
            self::MANAGER_REQUEST_INFO,
            self::ADG_REJECT,
            self::DENY_EXTENSION,
            self::MARK_NO_ROOM,
        ], true);
    }
}
