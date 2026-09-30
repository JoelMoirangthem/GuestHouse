<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\VisitPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingRequest extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Only applicant-supplied fields are mass-assignable.
     *
     * Every workflow column — status, request_no, the *_by and *_at stamps — is
     * deliberately excluded. Those are set by services after the state machine
     * has approved the transition. A fillable `status` would let a crafted form
     * field walk a request straight past both approval stages.
     */
    protected $fillable = [
        'purpose',
        'training_programme',
        'host_employee_id',
        'guest_of_name',
        'check_in_date',
        'check_out_date',
        'check_in_time',
        'check_out_time',
        'total_members',
        'preferred_room_type_id',
        'contact_mobile',
        'contact_email',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => VisitPurpose::class,
            'status' => RequestStatus::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'nights' => 'integer',
            'total_members' => 'integer',
            'rooms_needed' => 'integer',
            'submitted_at' => 'datetime',
            'manager_acted_at' => 'datetime',
            'more_info_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'adg_acted_at' => 'datetime',
            'availability_checked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    // These four all point at a User (SoftDeletes). A booking still belongs to
    // its applicant, host, manager and ADG even after that account is later
    // deactivated, so the relations must keep resolving trashed users. Without
    // withTrashed() a deactivated applicant makes requester() return null, and
    // every approval, allotment and notification that reads requester->... then
    // fails with a 500 ("Attempt to read property on null").
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function hostEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_employee_id')->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id')->withTrashed();
    }

    public function adg(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adg_id')->withTrashed();
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(RequestOccupant::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RequestDocument::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(Feedback::class);
    }

    /** One row per room — PLAN.md decision 1. */
    public function allotments(): HasMany
    {
        return $this->hasMany(Allotment::class);
    }

    /**
     * The stay has ended, normally or early — the only point at which the guest
     * can rate it. Cancelled and rejected requests never had a stay to rate.
     */
    public function stayCompleted(): bool
    {
        return in_array($this->status, [RequestStatus::CHECKED_OUT, RequestStatus::EARLY_CHECKOUT], true);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeAwaitingManager(Builder $q): Builder
    {
        return $q->where('status', RequestStatus::PENDING_MANAGER->value);
    }

    public function scopeAwaitingAdg(Builder $q): Builder
    {
        return $q->where('status', RequestStatus::PENDING_ADG->value);
    }

    /** The administrator's work queue — approved and needing a room decision. */
    public function scopeAwaitingAllotment(Builder $q): Builder
    {
        return $q->whereIn('status', [
            RequestStatus::PENDING_ALLOTMENT->value,
            RequestStatus::PARTIALLY_ALLOTTED->value,
        ]);
    }

    public function scopeSubmitted(Builder $q): Builder
    {
        return $q->where('status', '!=', RequestStatus::DRAFT->value);
    }

    // ------------------------------------------------------------------ helpers

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /** The primary occupant, who must carry the ID proof. */
    public function primaryOccupant(): ?RequestOccupant
    {
        return $this->occupants->firstWhere('is_primary', true);
    }

    /**
     * Who the booking is for: the primary guest, not the account that made it.
     *
     * For a training or guest booking — typically raised by the Manager — the
     * requester's name says nothing about who is arriving, so screens show the
     * guest instead. Falls back to the requester when no occupant is recorded.
     */
    public function guestName(): string
    {
        return $this->primaryOccupant()?->name
            ?? $this->occupants->first()?->name
            ?? $this->requester?->name
            ?? '—';
    }

    /**
     * One-line booking summary: purpose plus the programme, e.g.
     * "Training · Induction for IRS Probationers".
     *
     * The host is added ("Guest Visit · host: A. Sharma") only when the Manager
     * made the booking while signed in. Other guest bookings — notably those
     * from the public landing page, which fill the host in automatically with
     * the applicant — show just "Guest Visit".
     */
    public function bookingSummary(): string
    {
        $showHost = $this->requester?->isManager() ?? false;

        $detail = match (true) {
            filled($this->training_programme) => $this->training_programme,
            $showHost && $this->hostEmployee !== null => 'host: '.$this->hostEmployee->name,
            $showHost && filled($this->guest_of_name) => 'host: '.$this->guest_of_name,
            default => null,
        };

        return $this->purpose->label().($detail ? ' · '.$detail : '');
    }

    /**
     * Editable only as a draft or when the Manager has asked for more
     * information. Once it is with an approver, the applicant must not be able
     * to change the facts under review.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [
            RequestStatus::DRAFT,
            RequestStatus::MORE_INFO_MANAGER,
        ], true);
    }

    /**
     * Sequential reference, scoped per calendar year: REQ/2026/00001.
     *
     * Drawn from a per-year counter row (NumberSequence) so two simultaneous
     * submissions cannot take the same number or deadlock. The unique index on
     * request_no is the backstop.
     */
    public static function nextRequestNo(): string
    {
        return \App\Infrastructure\Persistence\NumberSequence::formatted('REQ');
    }
}
