{{--
  Manager action panel — SCREENS.md 3.4.

  Three actions. Reject and More Info both require written remarks, so each opens
  a small inline form rather than firing immediately: a destructive decision
  should never be one unguarded click away.
--}}

@php $canAct = $request->status === App\Domain\Enums\RequestStatus::PENDING_MANAGER; @endphp

<div id="manager-action" class="gh-card scroll-mt-20 p-5" x-data="{ mode: null }">
    <h2 class="gh-eyebrow mb-3">Action</h2>

    @if (! $canAct)
        <p class="text-sm text-[--color-ink-muted]">
            This request is no longer awaiting your review.
        </p>
    @else
        {{-- Approve. Rooms picked on the room board travel with it; selection
             state comes from the x-data on manager/requests/show. --}}
        <form method="POST" action="{{ route('manager.requests.approve', $request) }}"
              x-show="mode !== 'reject' && mode !== 'info'"
              @submit="setTimeout(() => $el.querySelector('button[type=submit]').disabled = true, 0)">
            @csrf

            <template x-if="typeof selected !== 'undefined'">
                <div>
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="room_ids[]" :value="id">
                    </template>

                    <div class="mb-3 rounded-lg border px-3 py-2.5 text-sm"
                         :class="selected.length ? 'border-navy-200 bg-navy-50' : 'border-[--color-line] bg-[--color-surface-sunken]'">
                        <p class="font-medium text-[--color-ink]" x-show="!selected.length">No rooms selected</p>
                        <p class="text-xs text-[--color-ink-muted]" x-show="!selected.length">
                            Select at least one room on the room board to approve.
                        </p>

                        <div x-show="selected.length" x-cloak>
                            <p class="mb-1.5 font-medium text-[--color-ink]">
                                Holding <span x-text="selected.length"></span>
                                <span x-text="selected.length === 1 ? 'room' : 'rooms'"></span>
                            </p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="id in selected" :key="'chip-' + id">
                                    <button type="button" @click="toggle(id)"
                                            class="inline-flex items-center gap-1 rounded-md bg-navy-800 px-2 py-0.5 text-xs font-semibold text-white hover:bg-navy-900"
                                            :aria-label="'Remove room ' + rooms[id].number">
                                        <span x-text="rooms[id].number"></span>
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </template>
                            </div>
                            <p class="mt-2 text-xs text-[--color-ink-muted]">
                                Confirmed as the allotment as soon as you approve.
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Room selection is mandatory: the button stays disabled until at
                 least one room is picked (the server enforces the same rule). --}}
            <button type="submit" class="gh-btn gh-btn-approve mb-2 w-full disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="typeof selected === 'undefined' || selected.length === 0">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/>
                </svg>
                <span>Approve &amp; allot rooms</span>
            </button>
        </form>

        <div class="grid grid-cols-2 gap-2" x-show="mode === null">
            <button type="button" @click="mode = 'reject'" class="gh-btn gh-btn-reject">Reject</button>
            <button type="button" @click="mode = 'info'" class="gh-btn gh-btn-info">More Info</button>
        </div>

        {{-- Reject form --}}
        <form method="POST" action="{{ route('manager.requests.reject', $request) }}"
              x-show="mode === 'reject'" x-cloak class="mt-1">
            @csrf
            <label for="reject_remarks" class="gh-label gh-required">Reason for rejection</label>
            <textarea id="reject_remarks" name="remarks" rows="3" required minlength="5"
                      class="gh-textarea"
                      placeholder="The applicant will see this reason."></textarea>
            <p class="gh-help">Required. The applicant is told why their request was refused.</p>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="mode = null" class="gh-btn gh-btn-secondary">Back</button>
                <button type="submit" class="gh-btn gh-btn-reject">Confirm Reject</button>
            </div>
        </form>

        {{-- More info form --}}
        <form method="POST" action="{{ route('manager.requests.moreInfo', $request) }}"
              x-show="mode === 'info'" x-cloak class="mt-1">
            @csrf
            <label for="info_remarks" class="gh-label gh-required">What is needed?</label>
            <textarea id="info_remarks" name="remarks" rows="3" required minlength="5"
                      class="gh-textarea"
                      placeholder="e.g. Please attach a legible copy of the Aadhaar card."></textarea>
            <p class="gh-help">The request returns to the applicant, who can edit and resend it.</p>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="mode = null" class="gh-btn gh-btn-secondary">Back</button>
                <button type="submit" class="gh-btn gh-btn-info">Send Request</button>
            </div>
        </form>
    @endif
</div>
