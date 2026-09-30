{{--
  Reviewer's view of a request — SCREENS.md 3.4, the details and history panels.

  Shared by the Manager and ADG screens. The two differ ONLY in their action
  panel, which each view supplies: the Manager has three buttons, the ADG has
  two. Sharing this partial means the facts under review are presented
  identically to both authorities.
--}}

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ---------- details ---------- --}}
    <div class="min-w-0 space-y-6 lg:col-span-2">

        <div class="gh-card p-5 sm:p-6">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-4 border-b border-[--color-line] pb-4">
                <div>
                    <p class="gh-eyebrow">Request</p>
                    <p class="mt-1 font-serif text-xl text-navy-900">{{ $request->request_no }}</p>
                </div>
                <x-status :status="$request->status" />
            </div>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-[--color-ink-muted]">Guest</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->guestName() }}</dd>
                </div>

                <div>
                    <dt class="text-xs text-[--color-ink-muted]">Purpose</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->purpose->label() }}</dd>
                </div>

                @if ($request->training_programme)
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-[--color-ink-muted]">Training programme</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->training_programme }}</dd>
                    </div>
                @endif

                {{-- Host shown only for bookings the Manager made while signed in;
                     see BookingRequest::bookingSummary(). --}}
                @if (($request->hostEmployee || $request->guest_of_name) && $request->requester?->isManager())
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Host employee</dt>
                        <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                            {{ $request->hostEmployee->name ?? $request->guest_of_name }}
                        </dd>
                    </div>
                @endif

                <div>
                    <dt class="text-xs text-[--color-ink-muted]">From</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                        {{ $request->check_in_date->format('d/m/Y') }}
                        @if ($request->check_in_time)
                            <span class="font-normal text-[--color-ink-muted]">{{ \Illuminate\Support\Carbon::parse($request->check_in_time)->format('H:i') }}</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs text-[--color-ink-muted]">To</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                        {{ $request->check_out_date->format('d/m/Y') }}
                        @if ($request->check_out_time)
                            <span class="font-normal text-[--color-ink-muted]">{{ \Illuminate\Support\Carbon::parse($request->check_out_time)->format('H:i') }}</span>
                        @endif
                        <span class="font-normal text-[--color-ink-muted]">({{ $request->nights }} {{ Str::plural('night', $request->nights) }})</span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs text-[--color-ink-muted]">Persons</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">{{ $request->total_members }}</dd>
                </div>

                <div>
                    <dt class="text-xs text-[--color-ink-muted]">Rooms requested</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                        {{ $request->rooms_needed }}
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-xs text-[--color-ink-muted]">Contact</dt>
                    <dd class="mt-0.5 text-sm font-medium text-[--color-ink]">
                        {{ $request->contact_mobile }} &middot; {{ $request->contact_email }}
                    </dd>
                </div>

                @if ($request->remarks)
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-[--color-ink-muted]">Applicant's remarks</dt>
                        <dd class="mt-0.5 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2 text-sm text-[--color-ink-soft]">
                            {{ $request->remarks }}
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Manager room board (PLAN.md decision 10). Supplied only by the
             Manager screen, and only while the request awaits that Manager. --}}
        @if (! empty($boardPartial))
            @include($boardPartial, ['board' => $board, 'request' => $request])
        @endif

        @php
            $heldRooms = $request->allotments()->occupying()->with(['room.roomType', 'allottedBy'])->get();
        @endphp

        @if ($heldRooms->isNotEmpty())
            <div class="gh-card p-5 sm:p-6">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="gh-eyebrow">
                        {{ $request->status === App\Domain\Enums\RequestStatus::PENDING_ADG ? 'Rooms held for this request' : 'Allotted rooms' }}
                    </h2>
                    <span class="text-xs text-[--color-ink-muted]">
                        {{ $heldRooms->count() }} of {{ $request->rooms_needed }} requested
                    </span>
                </div>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($heldRooms as $a)
                        <li class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm">
                            <span class="font-semibold text-emerald-900">{{ $a->room->room_number }}</span>
                            <span class="text-xs text-emerald-800">&middot; {{ $a->room->roomType->displayName() }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($request->status === App\Domain\Enums\RequestStatus::PENDING_ADG)
                    <p class="mt-3 text-xs text-[--color-ink-muted]">
                        Selected by {{ $heldRooms->first()->allottedBy->name ?? 'the Manager' }}. They are confirmed as the
                        allotment when the ADG approves, and released if the request is rejected.
                    </p>
                @endif
            </div>
        @endif

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
                            <th scope="col">Gender</th>
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
                                <td class="text-[--color-ink-soft]">{{ $o->gender ?? '—' }}</td>
                                <td class="text-[--color-ink-soft]">{{ $o->relation ?? '—' }}</td>
                                {{-- Masked. An approver never needs the full number,
                                     so they never see it. SECURITY.md section 2. --}}
                                <td class="text-[--color-ink-soft]">{{ $o->maskedIdProof() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- History — the audit trail, screen 3.4 --}}
        <div class="gh-card p-5 sm:p-6">
            <h2 class="gh-eyebrow mb-4">History</h2>

            @if ($history->isEmpty())
                <p class="text-sm text-[--color-ink-muted]">No activity recorded yet.</p>
            @else
                <ol class="relative space-y-4 border-l border-[--color-line] pl-5">
                    @foreach ($history as $entry)
                        <li class="relative">
                            <span class="absolute -left-[1.4375rem] top-1.5 h-2 w-2 rounded-full border-2 border-white bg-navy-400"></span>
                            <p class="text-sm font-medium text-[--color-ink]">{{ $entry->describe() }}</p>
                            <p class="mt-0.5 text-xs text-[--color-ink-muted]">
                                {{ $entry->created_at->format('d/m/Y, g:i a') }}
                                @if ($entry->actor)
                                    &middot; {{ $entry->actor->name }}
                                    <span class="text-[--color-ink-faint]">({{ ucfirst((string) $entry->actor_role) }})</span>
                                @endif
                            </p>
                            @if ($entry->remarks)
                                <p class="mt-1.5 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2 text-sm text-[--color-ink-soft]">
                                    {{ $entry->remarks }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    {{-- ---------- sidebar ---------- --}}
    <div class="space-y-6">

        @foreach (['action', 'room_ids', 'room_ids.*'] as $errKey)
            @error($errKey)
                <div class="gh-alert gh-alert-danger" role="alert"><span>{{ $message }}</span></div>
            @enderror
        @endforeach

        {{-- Each screen supplies its own action panel: the Manager has three
             buttons, the ADG has two. This is the only structural difference
             between the two reviewer screens. --}}
        @if (! empty($actionsPartial))
            @include($actionsPartial, ['request' => $request])
        @endif

        {{-- Documents --}}
        <div class="gh-card p-5">
            <h2 class="gh-eyebrow mb-3">Identity documents</h2>

            @forelse ($request->documents as $doc)
                <a href="{{ route('documents.show', $doc) }}" target="_blank" rel="noopener"
                   class="mb-2 flex items-center gap-2.5 rounded-lg border border-[--color-line] px-3 py-2 transition-colors hover:bg-[--color-surface-sunken]">
                    <x-icon name="document" class="h-4 w-4 shrink-0 text-[--color-ink-muted]" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-[--color-ink]">{{ $doc->docTypeLabel() }}</span>
                        <span class="block text-xs text-[--color-ink-faint]">{{ $doc->humanSize() }} &middot; opens in a new tab</span>
                    </span>
                </a>
            @empty
                <p class="text-sm text-[--color-ink-muted]">No documents attached.</p>
            @endforelse

            <p class="mt-3 text-xs text-[--color-ink-faint]">
                Document access is recorded in the audit trail.
            </p>
        </div>

        {{-- Reporting line, so the reviewer can see why it reached them --}}
        <div class="gh-card p-5">
            <h2 class="gh-eyebrow mb-3">Routing</h2>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-[--color-ink-muted]">Submitted</dt>
                    <dd class="text-right font-medium text-[--color-ink]">
                        {{ $request->submitted_at?->format('d/m/Y, g:i a') ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-[--color-ink-muted]">Manager</dt>
                    <dd class="text-right font-medium text-[--color-ink]">
                        {{ $request->manager?->name ?? $request->requester?->reportingManager?->name ?? 'Unassigned' }}
                    </dd>
                </div>
                @if ($request->manager_acted_at)
                    <div class="flex justify-between gap-3">
                        <dt class="text-[--color-ink-muted]">Manager decided</dt>
                        <dd class="text-right font-medium text-[--color-ink]">{{ $request->manager_acted_at->format('d/m/Y') }}</dd>
                    </div>
                @endif
                @if ($request->adg)
                    <div class="flex justify-between gap-3">
                        <dt class="text-[--color-ink-muted]">ADG</dt>
                        <dd class="text-right font-medium text-[--color-ink]">{{ $request->adg->name }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
</div>
