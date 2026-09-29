@extends('layouts.app')

@section('title', $request->request_no)
@section('heading', 'Request ' . $request->request_no)

@section('content')
    @php $s = $request->status; @endphp

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ---------- main column ---------- --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Status header --}}
            <div class="gh-card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="gh-eyebrow">Current status</p>
                        <div class="mt-2"><x-status :status="$s" /></div>
                    </div>
                    <div class="text-right">
                        <p class="gh-eyebrow">Submitted</p>
                        <p class="mt-1.5 text-sm text-[--color-ink-soft]">
                            {{ $request->submitted_at?->format('d/m/Y, g:i a') ?? 'Not yet submitted' }}
                        </p>
                    </div>
                </div>

                @if ($s === App\Domain\Enums\RequestStatus::MORE_INFO_MANAGER)
                    <div class="gh-alert gh-alert-warn mt-5" role="alert">
                        <div>
                            <p class="font-semibold">More information required</p>
                            @if ($request->more_info_note)<p class="mt-1">{{ $request->more_info_note }}</p>@endif
                        </div>
                    </div>
                @endif

                @if ($s === App\Domain\Enums\RequestStatus::PENDING_ALLOTMENT)
                    <div class="gh-alert gh-alert-info mt-5" role="note">
                        <span>
                            Approved by the Manager. The Administration is now
                            checking room availability and will confirm your allotment.
                        </span>
                    </div>
                @endif

                @if (in_array($s, [App\Domain\Enums\RequestStatus::REJECTED_MANAGER, App\Domain\Enums\RequestStatus::REJECTED_ADG], true))
                    <div class="gh-alert gh-alert-danger mt-5" role="alert">
                        <div>
                            <p class="font-semibold">Request rejected</p>
                            <p class="mt-1">{{ $request->adg_remarks ?? $request->manager_remarks ?? 'No reason recorded.' }}</p>
                        </div>
                    </div>
                @endif

                @if ($s === App\Domain\Enums\RequestStatus::NO_ROOM_AVAILABLE)
                    <div class="gh-alert gh-alert-danger mt-5" role="alert">
                        <span>
                            Your request was approved, but no room was available for these dates.
                            Please contact the Administration to discuss alternatives.
                        </span>
                    </div>
                @endif
            </div>

            {{-- Request details --}}
            <div class="gh-card p-5 sm:p-6">
                <h2 class="gh-eyebrow mb-4">Request details</h2>

                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Purpose</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->purpose->label() }}</dd>
                    </div>

                    @if ($request->training_programme)
                        <div>
                            <dt class="text-xs text-[--color-ink-muted]">Training programme</dt>
                            <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->training_programme }}</dd>
                        </div>
                    @endif

                    @if ($request->hostEmployee || $request->guest_of_name)
                        <div>
                            <dt class="text-xs text-[--color-ink-muted]">Host employee</dt>
                            <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                                {{ $request->hostEmployee->name ?? $request->guest_of_name }}
                            </dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Stay</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                            {{ $request->check_in_date->format('d/m/Y') }} &rarr; {{ $request->check_out_date->format('d/m/Y') }}
                            <span class="font-normal text-[--color-ink-muted]">({{ $request->nights }} {{ Str::plural('night', $request->nights) }})</span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Members</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->total_members }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Contact</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                            {{ $request->contact_mobile }}
                            <span class="block font-normal text-[--color-ink-muted]">{{ $request->contact_email }}</span>
                        </dd>
                    </div>

                    @if ($request->remarks)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-[--color-ink-muted]">Remarks</dt>
                            <dd class="mt-0.5 text-sm text-[--color-ink-soft]">{{ $request->remarks }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Occupants --}}
            <div class="gh-card overflow-hidden">
                <div class="border-b border-[--color-line] px-5 py-3.5 sm:px-6">
                    <h2 class="gh-eyebrow">Occupants ({{ $request->occupants->count() }})</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="gh-table">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Relation</th>
                                <th scope="col">ID proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($request->occupants as $o)
                                <tr>
                                    <td class="font-medium text-[--color-ink]">
                                        {{ $o->name }}
                                        @if ($o->is_primary)
                                            <span class="ml-1.5 rounded border border-[--color-line-strong] bg-[--color-surface-sunken] px-1.5 py-0.5 text-[0.625rem] font-semibold uppercase tracking-wide text-[--color-ink-muted]">Primary</span>
                                        @endif
                                    </td>
                                    <td class="text-[--color-ink-soft]">{{ $o->age ?? '—' }}</td>
                                    <td class="text-[--color-ink-soft]">{{ $o->relation ?? '—' }}</td>
                                    {{-- Masked, never the full number. SECURITY.md section 2. --}}
                                    <td class="text-[--color-ink-soft]">{{ $o->maskedIdProof() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ---------- sidebar ---------- --}}
        <div class="space-y-6">

            {{-- Progress --}}
            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-4">Approval progress</h2>

                @php
                    $steps = [
                        ['label' => 'Submitted', 'done' => $request->submitted_at !== null, 'at' => $request->submitted_at],
                        ['label' => 'Manager review', 'done' => $request->manager_acted_at !== null, 'at' => $request->manager_acted_at],
                        ['label' => 'Room allotment', 'done' => $s->holdsRooms(), 'at' => $request->availability_checked_at],
                    ];
                @endphp

                <ol class="space-y-3">
                    @foreach ($steps as $i => $step)
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[0.625rem] font-semibold
                                {{ $step['done']
                                    ? 'border-[--color-success] bg-[--color-success] text-white'
                                    : 'border-[--color-line-strong] bg-white text-[--color-ink-faint]' }}">
                                @if ($step['done'])
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/>
                                    </svg>
                                @else
                                    {{ $i + 1 }}
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium {{ $step['done'] ? 'text-[--color-ink]' : 'text-[--color-ink-muted]' }}">
                                    {{ $step['label'] }}
                                </span>
                                @if ($step['at'])
                                    <span class="block text-xs text-[--color-ink-faint]">{{ $step['at']->format('d/m/Y, g:i a') }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Documents --}}
            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-3">Identity documents</h2>

                @forelse ($request->documents as $doc)
                    <a href="{{ route('documents.show', $doc) }}" target="_blank" rel="noopener"
                       class="mb-2 flex items-center gap-2.5 rounded-lg border border-[--color-line] px-3 py-2 transition-colors hover:bg-[--color-surface-sunken]">
                        <x-icon name="document" class="h-4 w-4 shrink-0 text-[--color-ink-muted]" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-[--color-ink]">{{ $doc->docTypeLabel() }}</span>
                            <span class="block text-xs text-[--color-ink-faint]">{{ $doc->humanSize() }}</span>
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-[--color-ink-muted]">No documents attached.</p>
                @endforelse
            </div>

            {{-- Extension request, available only while actually staying --}}
            @if ($s === App\Domain\Enums\RequestStatus::CHECKED_IN && $request->isOwnedBy(auth()->user()))
                <div class="gh-card p-5" x-data="{ open: false }">
                    <h2 class="gh-eyebrow mb-3">Need to stay longer?</h2>

                    @error('extension')
                        <div class="gh-alert gh-alert-danger mb-3" role="alert"><span>{{ $message }}</span></div>
                    @enderror

                    <button type="button" @click="open = true" x-show="! open" class="gh-btn gh-btn-secondary w-full">
                        Request an extension
                    </button>

                    <form method="POST" action="{{ route('my.requests.extension', $request) }}" x-show="open" x-cloak>
                        @csrf
                        <label for="requested_check_out_date" class="gh-label gh-required">New check-out date</label>
                        <input id="requested_check_out_date" name="requested_check_out_date" type="date" required
                               class="gh-input"
                               min="{{ $request->check_out_date->copy()->addDay()->format('Y-m-d') }}"
                               value="{{ old('requested_check_out_date') }}">

                        <label for="ext_reason" class="gh-label mt-3">Reason</label>
                        <textarea id="ext_reason" name="reason" rows="2" class="gh-textarea"
                                  placeholder="Why do you need the extra nights?">{{ old('reason') }}</textarea>

                        {{-- Honest expectation-setting. The applicant cannot see room
                             availability, so the outcome genuinely is not theirs to
                             predict. --}}
                        <p class="gh-help">
                            The Administration will check whether your room is free for the
                            additional nights and let you know.
                        </p>

                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" @click="open = false" class="gh-btn gh-btn-secondary">Cancel</button>
                            <button type="submit" class="gh-btn gh-btn-primary">Send</button>
                        </div>
                    </form>
                </div>
            @endif

            @if ($s === App\Domain\Enums\RequestStatus::EXTENSION_REQUESTED)
                <div class="gh-card p-5">
                    <h2 class="gh-eyebrow mb-2">Extension requested</h2>
                    <p class="text-sm text-[--color-ink-muted]">
                        The Administration is reviewing your request for additional nights.
                        Your current check-out date of
                        {{ $request->check_out_date->format('d/m/Y') }} stands until they decide.
                    </p>
                </div>
            @endif

            {{-- Feedback, once the stay is over --}}
            @if ($request->stayCompleted() && $request->isOwnedBy(auth()->user()))
                @php $fb = $request->feedback; @endphp
                <div class="gh-card p-5">
                    <h2 class="gh-eyebrow mb-2">Your feedback</h2>

                    @error('feedback')
                        <div class="gh-alert gh-alert-danger mb-3" role="alert"><span>{{ $message }}</span></div>
                    @enderror

                    @if ($fb)
                        <dl class="space-y-1.5 text-sm">
                            @foreach (App\Models\Feedback::ASPECTS as $field => $label)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-[--color-ink-muted]">{{ $label }}</dt>
                                    <dd class="font-medium text-[--color-ink]">{{ $fb->{$field} }} / 5</dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-3 text-xs text-[--color-ink-faint]">Recorded {{ $fb->submitted_at->format('d/m/Y, g:i a') }}. Thank you.</p>
                    @else
                        <p class="mb-3 text-sm text-[--color-ink-muted]">Tell the Administration how your stay went. It takes a minute.</p>
                        @can('submitFeedback', $request)
                            <a href="{{ route('my.requests.feedback', $request) }}" class="gh-btn gh-btn-primary w-full">Give feedback</a>
                        @endcan
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            @if ($request->isOwnedBy(auth()->user()) && $s->issuesLetter())
                <div class="gh-card p-5">
                    <h2 class="gh-eyebrow mb-2">Allotment letter</h2>
                    <p class="mb-3 text-sm text-[--color-ink-muted]">Carry it with you. The front desk scans its QR code to check you in.</p>
                    <a href="{{ route('my.requests.letter', $request) }}" target="_blank" rel="noopener" class="gh-btn gh-btn-primary w-full">
                        Download letter (PDF)
                    </a>
                </div>
            @endif

            @canany(['update', 'cancel'], $request)
                <div class="gh-card p-5">
                    <h2 class="gh-eyebrow mb-3">Actions</h2>

                    <div class="space-y-2">
                        @can('update', $request)
                            <a href="{{ route('my.requests.edit', $request) }}" class="gh-btn gh-btn-secondary w-full">
                                Edit request
                            </a>
                        @endcan

                        @if ($s === App\Domain\Enums\RequestStatus::MORE_INFO_MANAGER)
                            <form method="POST" action="{{ route('my.requests.resubmit', $request) }}">
                                @csrf
                                <button type="submit" class="gh-btn gh-btn-primary w-full">Resend for review</button>
                            </form>
                        @endif

                        @can('cancel', $request)
                            <form method="POST" action="{{ route('my.requests.cancel', $request) }}"
                                  x-data
                                  @submit="if (! confirm('Cancel request {{ $request->request_no }}? This cannot be undone.')) $event.preventDefault()">
                                @csrf
                                <input type="hidden" name="cancel_reason" value="Withdrawn by applicant">
                                <button type="submit" class="gh-btn gh-btn-reject w-full">Cancel request</button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endcanany
        </div>
    </div>
@endsection
