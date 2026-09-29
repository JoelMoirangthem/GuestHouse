{{--
  ADG action panel — SCREENS.md 3.4, ADG variant.

  TWO actions: Approve and Reject.

  There is no "More Info" button here, and that is not a styling choice. PLAN.md
  decision 5 restricts the ADG to approving or rejecting. Compare with
  manager/requests/_actions.blade.php, which has three.

  Note also that hiding a button is never the control. The route, the permission
  and the state-machine rule are all absent too, so a crafted POST fails even
  though this panel is the only visible difference.
--}}

@php $canAct = $request->status === App\Domain\Enums\RequestStatus::PENDING_ADG; @endphp

<div class="gh-card p-5" x-data="{ mode: null }">
    <h2 class="gh-eyebrow mb-3">Action</h2>

    @if (! $canAct)
        <p class="text-sm text-[--color-ink-muted]">
            This request is no longer awaiting your approval.
        </p>
    @else
        @php $heldCount = $request->allotments()->occupying()->count(); @endphp
        <p class="mb-3 text-xs leading-relaxed text-[--color-ink-muted]">
            @if ($heldCount > 0)
                The Manager has reviewed this request and is holding {{ $heldCount }} {{ Str::plural('room', $heldCount) }}.
                Approving confirms them as the allotment; rejecting releases them.
            @else
                The Manager has already reviewed this request. On your approval, the
                Administration will check room availability.
            @endif
        </p>

        {{-- Approve --}}
        <form method="POST" action="{{ route('adg.requests.approve', $request) }}"
              x-show="mode === null">
            @csrf
            <label for="approve_remarks" class="gh-label">Remarks (optional)</label>
            <textarea id="approve_remarks" name="remarks" rows="2" class="gh-textarea mb-3"></textarea>

            <button type="submit" class="gh-btn gh-btn-approve w-full">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/>
                </svg>
                Approve
            </button>
        </form>

        <button type="button" @click="mode = 'reject'" x-show="mode === null"
                class="gh-btn gh-btn-reject mt-2 w-full">
            Reject
        </button>

        {{-- Reject form --}}
        <form method="POST" action="{{ route('adg.requests.reject', $request) }}"
              x-show="mode === 'reject'" x-cloak>
            @csrf
            <label for="adg_reject_remarks" class="gh-label gh-required">Reason for rejection</label>
            <textarea id="adg_reject_remarks" name="remarks" rows="3" required minlength="5"
                      class="gh-textarea"
                      placeholder="The applicant will see this reason."></textarea>
            <p class="gh-help">Required. This decision is final.</p>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="mode = null" class="gh-btn gh-btn-secondary">Back</button>
                <button type="submit" class="gh-btn gh-btn-reject">Confirm Reject</button>
            </div>
        </form>
    @endif
</div>
