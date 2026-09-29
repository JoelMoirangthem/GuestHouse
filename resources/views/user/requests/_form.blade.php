{{--
  Booking request form — SCREENS.md 3.2.

  Shared by create and edit. Alpine handles three pieces of interactivity:
    1. revealing purpose-specific fields
    2. keeping the occupant rows in step with the member count
    3. computing the night count

  All three are conveniences. Every one of them is also enforced server-side in
  StoreBookingRequestRequest, because a hidden field is not a control.

  NOTE what this form does NOT contain: any availability information. No room
  counts, no calendar of free rooms, no "N rooms left" hint. Core Rule 2 — the
  applicant declares intent and the Administration decides on rooms afterwards.
--}}

@php
    $r = $request ?? null;
    $oldOccupants = old('occupants', $r?->occupants->map(fn ($o) => [
        'name' => $o->name,
        'age' => $o->age,
        'gender' => $o->gender,
        'relation' => $o->relation,
        'id_proof_type' => $o->id_proof_type,
        'id_proof_number' => '',
    ])->all() ?? [['name' => '', 'age' => '', 'gender' => '', 'relation' => '', 'id_proof_type' => 'AADHAAR', 'id_proof_number' => '']]);
@endphp

<form method="POST"
      action="{{ $action }}"
      enctype="multipart/form-data"
      novalidate
      @submit="setTimeout(() => submitting = true, 0)"
      x-data="{
          submitting: false,
          purpose: @js(old('purpose', $r?->purpose->value ?? '')),
          checkIn: @js(old('check_in_date', $r?->check_in_date?->format('Y-m-d') ?? '')),
          checkOut: @js(old('check_out_date', $r?->check_out_date?->format('Y-m-d') ?? '')),
          occupants: @js(array_values($oldOccupants)),

          get nights() {
              if (!this.checkIn || !this.checkOut) return 0;
              const ms = new Date(this.checkOut) - new Date(this.checkIn);
              return ms > 0 ? Math.round(ms / 86400000) : 0;
          },
          get members() { return this.occupants.length; },
          get roomsNeeded() { return Math.max(1, Math.ceil(this.members / 2)); },

          addOccupant() {
              this.occupants.push({ name: '', age: '', gender: '', relation: '', id_proof_type: 'AADHAAR', id_proof_number: '' });
          },
          removeOccupant(i) {
              if (this.occupants.length > 1) this.occupants.splice(i, 1);
          },
      }">
    @csrf
    @if ($method === 'PUT')
        @method('PUT')
    @endif

    {{-- Error summary.

         Every validation message is listed here, not only the ones that have a
         matching @error directive beside a field. Occupant rows are rendered by
         Alpine from a template, so per-field messages cannot always be placed
         next to the offending input — without this block a rejected submission
         would look like nothing happened at all.

         role=alert + tabindex makes it announced and focusable for screen readers. --}}
    @if ($errors->any())
        <div class="gh-alert gh-alert-danger mb-6" role="alert" tabindex="-1" x-init="$el.focus()">
            <div class="min-w-0">
                <p class="font-semibold">
                    {{ $errors->count() === 1 ? 'There is a problem with this request' : 'There are ' . $errors->count() . ' problems with this request' }}
                </p>
                <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="space-y-6">

        {{-- ============ APPLICANT ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-applicant">
            <h2 id="sec-applicant" class="gh-eyebrow mb-4">Applicant</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="applicant_name" class="gh-label">Full name</label>
                    <input id="applicant_name" type="text" class="gh-input" value="{{ auth()->user()->name }}" readonly>
                </div>
                <div>
                    <label for="applicant_code" class="gh-label">Employee code</label>
                    <input id="applicant_code" type="text" class="gh-input" value="{{ auth()->user()->employee_code ?? '—' }}" readonly>
                </div>
                <div>
                    <label for="contact_mobile" class="gh-label gh-required">Mobile number</label>
                    <input id="contact_mobile" name="contact_mobile" type="tel" inputmode="numeric"
                           maxlength="10" required class="gh-input"
                           value="{{ old('contact_mobile', $r->contact_mobile ?? auth()->user()->mobile) }}"
                           @error('contact_mobile') aria-invalid="true" aria-describedby="err-mobile" @enderror>
                    @error('contact_mobile')<p id="err-mobile" class="gh-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="contact_email" class="gh-label gh-required">Email address</label>
                    <input id="contact_email" name="contact_email" type="email" required class="gh-input"
                           value="{{ old('contact_email', $r->contact_email ?? auth()->user()->email) }}"
                           @error('contact_email') aria-invalid="true" aria-describedby="err-email" @enderror>
                    @error('contact_email')<p id="err-email" class="gh-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        {{-- ============ PURPOSE ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-purpose">
            <h2 id="sec-purpose" class="gh-eyebrow mb-1">Purpose of visit</h2>
            <p class="mb-4 text-sm text-[--color-ink-muted]">Select one. This determines what else we need from you.</p>

            <fieldset>
                <legend class="sr-only">Purpose of visit</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($purposes as $p)
                        <label class="gh-choice">
                            <input type="radio" name="purpose" value="{{ $p->value }}"
                                   x-model="purpose" required class="sr-only">
                            <span class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[--color-line] bg-[--color-surface-sunken] text-navy-700"
                                      :class="purpose === @js($p->value) && 'border-navy-300 bg-navy-100'">
                                    <x-icon :name="$p->icon()" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-[--color-ink]">{{ $p->label() }}</span>
                                    <span class="mt-0.5 block text-xs leading-snug text-[--color-ink-muted]">{{ $p->description() }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @error('purpose')<p class="gh-error" role="alert">{{ $message }}</p>@enderror

            {{-- Training branch --}}
            <div x-show="purpose === 'TRAINING'" x-cloak x-transition.opacity class="mt-5 border-t border-[--color-line] pt-5">
                <label for="training_programme" class="gh-label gh-required">Training programme name</label>
                <input id="training_programme" name="training_programme" type="text" class="gh-input"
                       value="{{ old('training_programme', $r->training_programme ?? '') }}"
                       placeholder="e.g. Induction Training for IRS Probationers">
                @error('training_programme')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
            </div>

            {{-- Guest branch --}}
            <div x-show="purpose === 'GUEST'" x-cloak x-transition.opacity class="mt-5 grid gap-4 border-t border-[--color-line] pt-5 sm:grid-cols-2">
                <div>
                    <label for="host_employee_id" class="gh-label gh-required">Host employee</label>
                    <select id="host_employee_id" name="host_employee_id" class="gh-select">
                        <option value="">Select the employee hosting you</option>
                        @foreach ($hosts as $host)
                            <option value="{{ $host->id }}" @selected(old('host_employee_id', $r->host_employee_id ?? null) == $host->id)>
                                {{ $host->name }}@if ($host->employee_code) &middot; {{ $host->employee_code }}@endif
                            </option>
                        @endforeach
                    </select>
                    @error('host_employee_id')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="guest_of_name" class="gh-label">Or enter the host's name</label>
                    <input id="guest_of_name" name="guest_of_name" type="text" class="gh-input"
                           value="{{ old('guest_of_name', $r->guest_of_name ?? '') }}">
                    <p class="gh-help">Use this only if the host is not in the list above.</p>
                </div>
            </div>
        </section>

        {{-- ============ STAY ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-stay">
            <h2 id="sec-stay" class="gh-eyebrow mb-4">Stay details</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="check_in_date" class="gh-label gh-required">From date</label>
                    <input id="check_in_date" name="check_in_date" type="date" required class="gh-input"
                           x-model="checkIn" min="{{ now()->format('Y-m-d') }}"
                           @error('check_in_date') aria-invalid="true" @enderror>
                    @error('check_in_date')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="check_out_date" class="gh-label gh-required">To date</label>
                    <input id="check_out_date" name="check_out_date" type="date" required class="gh-input"
                           x-model="checkOut" :min="checkIn || '{{ now()->format('Y-m-d') }}'"
                           @error('check_out_date') aria-invalid="true" @enderror>
                    @error('check_out_date')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="nights" class="gh-label">Nights</label>
                    <input id="nights" type="text" class="gh-input" readonly :value="nights" aria-live="polite">
                    <p class="gh-help">Checkout day is not charged as a night.</p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="total_members" class="gh-label">Total members</label>
                    <input id="total_members" type="number" name="total_members" class="gh-input" readonly :value="members">
                    <p class="gh-help">Derived from the occupant list below.</p>
                </div>
                <div>
                    <label for="rooms_estimate" class="gh-label">Rooms likely required</label>
                    <input id="rooms_estimate" type="text" class="gh-input" readonly :value="roomsNeeded">
                    <p class="gh-help">
                        An estimate only. The Administration decides the final allotment.
                    </p>
                </div>
            </div>

            {{-- The single most important expectation-setting sentence on this
                 screen. Without it, applicants assume submitting reserves a room. --}}
            <div class="gh-alert gh-alert-info mt-5" role="note">
                <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Room availability is checked by the Administration only after your request
                    is approved by the Manager. Submitting this form does not
                    reserve a room.
                </span>
            </div>
        </section>

        {{-- ============ OCCUPANTS ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-occupants">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 id="sec-occupants" class="gh-eyebrow">Occupants</h2>
                    <p class="mt-1 text-sm text-[--color-ink-muted]">
                        Everyone who will stay. The first person is the primary guest.
                    </p>
                </div>
                <button type="button" @click="addOccupant()" class="gh-btn gh-btn-secondary shrink-0">
                    Add person
                </button>
            </div>

            @error('occupants')
                <div class="gh-alert gh-alert-danger mb-4" role="alert"><span>{{ $message }}</span></div>
            @enderror

            <div class="space-y-3">
                <template x-for="(o, i) in occupants" :key="i">
                    <div class="rounded-xl border border-[--color-line] bg-[--color-surface-sunken] p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <span class="text-xs font-semibold text-[--color-ink-soft]">
                                <span x-text="i === 0 ? 'Primary guest' : 'Person ' + (i + 1)"></span>
                            </span>
                            <button type="button" x-show="occupants.length > 1" @click="removeOccupant(i)"
                                    class="text-xs font-medium text-[--color-danger] hover:underline"
                                    :aria-label="'Remove person ' + (i + 1)">
                                Remove
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="lg:col-span-2">
                                <label class="gh-label gh-required" :for="'occ-name-' + i">Full name</label>
                                <input :id="'occ-name-' + i" :name="'occupants[' + i + '][name]'"
                                       type="text" x-model="o.name" required class="gh-input">
                            </div>
                            <div>
                                <label class="gh-label" :for="'occ-age-' + i">Age</label>
                                <input :id="'occ-age-' + i" :name="'occupants[' + i + '][age]'"
                                       type="number" min="0" max="120" x-model="o.age" class="gh-input">
                            </div>
                            <div>
                                <label class="gh-label" :for="'occ-gender-' + i">Gender</label>
                                <select :id="'occ-gender-' + i" :name="'occupants[' + i + '][gender]'"
                                        x-model="o.gender" class="gh-select">
                                    <option value="">Not stated</option>
                                    <option value="M">Male</option>
                                    <option value="F">Female</option>
                                    <option value="O">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="gh-label" :for="'occ-rel-' + i">Relation</label>
                                <input :id="'occ-rel-' + i" :name="'occupants[' + i + '][relation]'"
                                       type="text" x-model="o.relation" class="gh-input"
                                       placeholder="Self, spouse, colleague">
                            </div>
                            <div>
                                <label class="gh-label" :for="'occ-idt-' + i">ID proof type</label>
                                <select :id="'occ-idt-' + i" :name="'occupants[' + i + '][id_proof_type]'"
                                        x-model="o.id_proof_type" class="gh-select">
                                    <option value="AADHAAR">Aadhaar</option>
                                    <option value="PAN">PAN</option>
                                    <option value="PASSPORT">Passport</option>
                                    <option value="OFFICE_ID">Office ID</option>
                                    <option value="OTHER">Other</option>
                                </select>
                            </div>
                            <div class="lg:col-span-2">
                                <label class="gh-label" :for="'occ-idn-' + i">ID proof number</label>
                                <input :id="'occ-idn-' + i" :name="'occupants[' + i + '][id_proof_number]'"
                                       type="text" x-model="o.id_proof_number" class="gh-input"
                                       autocomplete="off" maxlength="40">
                                <p class="gh-help">Stored encrypted. Displayed only as the last four digits.</p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        {{-- ============ DOCUMENTS ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-docs">
            <h2 id="sec-docs" class="gh-eyebrow mb-1">Identity proof</h2>
            <p class="mb-4 text-sm text-[--color-ink-muted]">
                Attach the primary guest's identity document.
            </p>

            @if ($r && $r->documents->isNotEmpty())
                <ul class="mb-4 space-y-2">
                    @foreach ($r->documents as $doc)
                        <li class="flex items-center justify-between gap-3 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2">
                            <span class="min-w-0 truncate text-sm text-[--color-ink-soft]">
                                {{ $doc->original_filename }}
                                <span class="text-xs text-[--color-ink-faint]">({{ $doc->humanSize() }})</span>
                            </span>
                            <a href="{{ route('documents.show', $doc) }}" target="_blank" rel="noopener"
                               class="shrink-0 text-sm font-medium text-navy-700 hover:underline">View</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <label for="documents" class="gh-label @if (! $r || $r->documents->isEmpty()) gh-required @endif">
                Upload document
            </label>
            <input id="documents" name="documents[]" type="file" multiple
                   accept=".pdf,.jpg,.jpeg,.png"
                   class="gh-input file:mr-3 file:rounded-md file:border-0 file:bg-navy-800 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white"
                   @error('documents') aria-invalid="true" @enderror>
            <p class="gh-help">PDF, JPG or PNG. Up to 5 MB each.</p>
            @error('documents')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
            @error('documents.0')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
        </section>

        {{-- ============ REMARKS ============ --}}
        <section class="gh-card p-5 sm:p-6" aria-labelledby="sec-remarks">
            <h2 id="sec-remarks" class="gh-eyebrow mb-4">Additional information</h2>
            <label for="remarks" class="gh-label">Remarks</label>
            <textarea id="remarks" name="remarks" rows="3" class="gh-textarea"
                      placeholder="Anything the Administration should know (accessibility needs, late arrival, and so on).">{{ old('remarks', $r->remarks ?? '') }}</textarea>
            @error('remarks')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
        </section>

        {{-- ============ ACTIONS ============ --}}
        <div class="flex flex-wrap items-center justify-end gap-3 pb-2">
            <a href="{{ route('my.requests.index') }}" class="gh-btn gh-btn-secondary">Cancel</a>

            {{-- Double-submit guard.

                 The disable happens on the FORM's submit event and is deferred by
                 a timeout. Disabling a submit button inside its own click handler
                 cancels the submission outright — the browser drops the event
                 because the control it originated from is no longer enabled. That
                 bug made this form silently do nothing, and it is invisible to
                 server-side tests because no request is ever sent. --}}
            <button type="submit" class="gh-btn gh-btn-primary" :disabled="submitting">
                <span x-text="submitting ? 'Submitting…' : @js($submitLabel)"></span>
            </button>
        </div>
    </div>
</form>
