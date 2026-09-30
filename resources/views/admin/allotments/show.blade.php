@extends('layouts.app')

@section('title', 'Allotment ' . $request->request_no)
@section('heading', 'Allotment Details')

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

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
                        <dd class="mt-0.5 font-medium text-[--color-ink]">
                            {{ $request->guestName() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Booking</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->bookingSummary() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Check-in</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->check_in_date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Check-out</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->check_out_date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Allotted by</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">
                            {{ $allotments->first()?->allottedBy?->name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Allotted on</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">
                            {{ $allotments->first()?->allotted_at?->format('d/m/Y, g:i a') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="gh-card overflow-hidden">
                <div class="border-b border-[--color-line] px-5 py-3.5">
                    <h2 class="gh-eyebrow">Rooms ({{ $allotments->count() }})</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="gh-table">
                        <thead>
                            <tr>
                                <th scope="col">Allotment No.</th>
                                <th scope="col">Room</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-right">Occupants</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allotments as $a)
                                <tr>
                                    <td class="text-[--color-ink-soft]">{{ $a->allotment_no }}</td>
                                    <td class="font-medium text-[--color-ink]">{{ $a->room->room_number }}</td>
                                    <td class="text-[--color-ink-soft]">{{ $a->room->roomType->displayName() }}</td>
                                    <td class="text-right text-[--color-ink-soft]">{{ $a->occupants_count }}</td>
                                    <td><span class="gh-status {{ $a->status->badgeClasses() }}">{{ $a->status->label() }}</span></td>
                                    <td class="text-right font-medium text-[--color-ink]">
                                        &#8377; {{ number_format((float) $a->total_amount, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-[--color-line-strong] bg-[--color-surface-sunken]">
                                <td colspan="5" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-[--color-ink-muted]">
                                    Estimated total
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold">
                                    &#8377; {{ number_format((float) $allotments->where('status.value', '!=', 'CANCELLED')->sum(fn ($a) => (float) $a->total_amount), 2) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="gh-card p-5 sm:p-6">
                <h2 class="gh-eyebrow mb-4">History</h2>
                <ol class="relative space-y-4 border-l border-[--color-line] pl-5">
                    @foreach ($history as $entry)
                        <li class="relative">
                            <span class="absolute -left-[1.4375rem] top-1.5 h-2 w-2 rounded-full border-2 border-white bg-navy-400"></span>
                            <p class="text-sm font-medium text-[--color-ink]">{{ $entry->describe() }}</p>
                            <p class="mt-0.5 text-xs text-[--color-ink-muted]">
                                {{ $entry->created_at->format('d/m/Y, g:i a') }}
                                @if ($entry->actor) &middot; {{ $entry->actor->name }} @endif
                            </p>
                            @if ($entry->remarks)
                                <p class="mt-1.5 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2 text-sm text-[--color-ink-soft]">
                                    {{ $entry->remarks }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Occupants. Numbers are masked; the full value needs an audited reveal. --}}
            <div class="gh-card overflow-hidden">
                <div class="border-b border-[--color-line] px-5 py-3.5 sm:px-6">
                    <h2 class="gh-eyebrow">Occupants ({{ $request->occupants->count() }})</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="gh-table">
                        <thead><tr><th scope="col">Name</th><th scope="col">ID proof</th><th scope="col"><span class="sr-only">Reveal</span></th></tr></thead>
                        <tbody>
                            @foreach ($request->occupants as $o)
                                <tr x-data="{ open: false }">
                                    <td class="font-medium text-[--color-ink]">{{ $o->name }}</td>
                                    <td class="text-[--color-ink-soft]">{{ $o->maskedIdProof() ?? '—' }}</td>
                                    <td class="text-right">
                                        @if ($o->id_proof_last4 !== null)
                                            @can('revealIdProof', $request)
                                                <button type="button" @click="open = !open" :aria-expanded="open" x-show="!open"
                                                        class="gh-btn gh-btn-ghost text-[0.8125rem]">Reveal<span class="sr-only"> full number for {{ $o->name }}</span></button>
                                                <form method="POST" action="{{ route('admin.occupants.reveal', $o) }}" x-show="open" x-cloak
                                                      class="flex items-end justify-end gap-2">
                                                    @csrf
                                                    <label for="reason-{{ $o->id }}" class="sr-only">Reason for revealing</label>
                                                    <input id="reason-{{ $o->id }}" name="reason" type="text" required minlength="5" maxlength="255"
                                                           class="gh-input" placeholder="Reason (audited)">
                                                    <button type="submit" class="gh-btn gh-btn-secondary text-[0.8125rem]">Show</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($fb = $request->feedback)
                <div class="gh-card p-5 sm:p-6">
                    <h2 class="gh-eyebrow mb-4">Guest feedback</h2>
                    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        @foreach (App\Models\Feedback::ASPECTS as $field => $label)
                            <div class="flex justify-between gap-3">
                                <dt class="text-[--color-ink-muted]">{{ $label }}</dt>
                                <dd class="font-medium text-[--color-ink]">{{ $fb->{$field} }} / 5</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($fb->comments)
                        <p class="mt-4 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2 text-sm text-[--color-ink-soft]">{{ $fb->comments }}</p>
                    @endif
                    <p class="mt-3 text-xs text-[--color-ink-faint]">Submitted {{ $fb->submitted_at->format('d/m/Y, g:i a') }}</p>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-3">Actions</h2>
                <div class="space-y-2">
                    @if ($request->status->allowsAvailabilityCheck())
                        <a href="{{ route('admin.allotments.create', $request) }}" class="gh-btn gh-btn-primary w-full">
                            Allot more rooms
                        </a>
                    @endif
                    @php $letterFor = $allotments->first(fn ($a) => $a->status->occupiesRoom()); @endphp
                    @if ($letterFor && $request->status->issuesLetter())
                        <a href="{{ route('admin.allotments.letter', $letterFor) }}" target="_blank" rel="noopener" class="gh-btn gh-btn-primary w-full">
                            Print allotment letter (PDF)
                        </a>
                    @endif
                    <a href="{{ route('admin.allotments.index') }}" class="gh-btn gh-btn-secondary w-full">
                        Back to queue
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
