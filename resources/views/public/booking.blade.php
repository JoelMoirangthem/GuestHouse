{{--
  Public requisition form ("Room & Stay Details"). No sign-in required.

  The employee is identified by Employee ID / PPO No. + registered mobile; see
  PublicBookingController. Alpine only reveals purpose-specific fields and drives
  the rooms stepper; every rule is enforced again in PublicBookingRequest.

  Like the signed-in form, this screen shows NO availability information
  (Core Rule 2). Submitting it does not reserve a room.
--}}
@extends('layouts.public')

@section('title', 'Room & Stay Details')
@section('heading', 'Room & Stay Details')
@section('back', route('landing'))
@section('footer_padding', 'pb-28')

@php
    // Short labels from the mobile design, in its order.
    $visitTypes = [
        'TRAINING' => 'Training',
        'GUEST' => 'Guest',
        'SELF' => 'Personal',
    ];
    $today = now()->format('Y-m-d');
@endphp

@section('content')
<form method="POST"
      action="{{ route('public.booking.store') }}"
      enctype="multipart/form-data"
      novalidate
      @submit="if (! agreed) { $event.preventDefault(); openTerms(); return; } setTimeout(() => submitting = true, 0)"
      x-data="{
          submitting: false,
          purpose: @js(old('purpose', '')),
          rooms: {{ max(1, min(10, (int) old('rooms', 1))) }},
          checkIn: @js(old('check_in_date', '')),
          fileName: '',
          fileError: '',
          agreed: false,
          // Step 1: 'Submit Requisition' checks the form, then shows the terms.
          // Step 2: ticking the checkbox reveals 'Final Submit', which posts.
          openTerms() {
              if (this.fileError !== '' || ! this.$root.reportValidity()) return;
              if (this.$refs.terms.open) return;
              this.agreed = false;
              this.$refs.terms.showModal();
              this.$refs.termsBody.scrollTop = 0;
          },
          maxBytes: {{ (int) config('gh.upload.max_kb') * 1024 }},
          pick(e) {
              const f = e.target.files[0];
              this.fileError = '';
              this.fileName = f ? f.name : '';
              if (f && f.size > this.maxBytes) {
                  this.fileError = 'This file is larger than 5 MB. Choose a smaller one.';
              }
          },
      }">
    @csrf

    <h2 class="mb-3 text-lg font-semibold text-[--color-ink]">1. Provide details for your stay</h2>

    @if ($errors->any())
        <div class="gh-alert gh-alert-danger mb-4" role="alert" tabindex="-1" x-init="$el.focus()">
            <div class="min-w-0">
                <p class="font-semibold">
                    {{ $errors->count() === 1 ? 'There is a problem with this requisition' : 'There are ' . $errors->count() . ' problems with this requisition' }}
                </p>
                <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="space-y-4">

        {{-- ============ PERSONAL DETAILS ============ --}}
        <section class="gh-card p-4 sm:p-5" aria-labelledby="sec-personal">
            <h3 id="sec-personal" class="mb-3 text-base font-semibold text-[--color-ink]">Personal Details</h3>

            <div class="space-y-4">
                <div>
                    <label for="employee_code" class="gh-label">Employee ID / PPO No. <span class="text-[--color-ink-muted]">(optional)</span></label>
                    <input id="employee_code" name="employee_code" type="text" class="gh-input"
                           placeholder="Enter your ID (optional)" autocomplete="off" autocapitalize="characters"
                           maxlength="30" value="{{ old('employee_code') }}"
                           @error('employee_code') aria-invalid="true" aria-describedby="err-code" @enderror>
                    @error('employee_code')<p id="err-code" class="gh-error">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="contact_mobile" class="gh-label gh-required">Contact Number</label>
                    <input id="contact_mobile" name="contact_mobile" type="tel" inputmode="numeric"
                           autocomplete="tel-national" maxlength="10" class="gh-input" required
                           pattern="[6-9][0-9]{9}" title="Enter a valid 10-digit mobile number"
                           placeholder="Enter mobile number" value="{{ old('contact_mobile') }}"
                           aria-describedby="help-mobile @error('contact_mobile') err-mobile @enderror"
                           @error('contact_mobile') aria-invalid="true" @enderror>
                    <p id="help-mobile" class="gh-help">The mobile number registered with the Guest House.</p>
                    @error('contact_mobile')<p id="err-mobile" class="gh-error">{{ $message }}</p>@enderror
                </div>

                <fieldset>
                    <legend class="gh-label gh-required">Type of Visit</legend>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($visitTypes as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="purpose" value="{{ $value }}" x-model="purpose"
                                       class="peer sr-only" required>
                                <span class="block rounded-full border border-[--color-line-strong] bg-[--color-surface-sunken] px-2 py-2 text-center text-sm font-medium text-[--color-ink-soft] transition-colors
                                             peer-checked:border-navy-800 peer-checked:bg-navy-800 peer-checked:text-white
                                             peer-focus-visible:ring-2 peer-focus-visible:ring-navy-500 peer-focus-visible:ring-offset-2">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('purpose')<p class="gh-error">{{ $message }}</p>@enderror
                </fieldset>

                {{-- Purpose-specific detail the approval chain needs. --}}
                <div x-show="purpose === 'TRAINING'" x-cloak>
                    <label for="training_programme" class="gh-label gh-required">Training programme name</label>
                    <input id="training_programme" name="training_programme" type="text" class="gh-input"
                           maxlength="150" value="{{ old('training_programme') }}"
                           :required="purpose === 'TRAINING'"
                           placeholder="e.g. Induction Training for IRS Probationers"
                           @error('training_programme') aria-invalid="true" @enderror>
                    @error('training_programme')<p class="gh-error">{{ $message }}</p>@enderror
                </div>

                <div x-show="purpose === 'GUEST'" x-cloak>
                    <label for="guest_name" class="gh-label gh-required">Guest's full name</label>
                    <input id="guest_name" name="guest_name" type="text" class="gh-input"
                           maxlength="120" value="{{ old('guest_name') }}"
                           :required="purpose === 'GUEST'"
                           @error('guest_name') aria-invalid="true" @enderror>
                    <p class="gh-help">You will be recorded as the host.</p>
                    @error('guest_name')<p class="gh-error">{{ $message }}</p>@enderror
                </div>

                <div x-show="purpose === 'SELF'" x-cloak>
                    <label for="employee_name" class="gh-label">Employee name</label>
                    <input id="employee_name" name="employee_name" type="text" class="gh-input"
                           maxlength="120" value="{{ old('employee_name') }}"
                           placeholder="Name of the employee staying"
                           @error('employee_name') aria-invalid="true" @enderror>
                    <p class="gh-help">Optional &mdash; leave blank to use the name on your account.</p>
                    @error('employee_name')<p class="gh-error">{{ $message }}</p>@enderror
                </div>

                <div>
                    <span class="gh-label" id="lbl-idcard">Upload Employee ID <span class="text-[--color-ink-muted]">(optional)</span></span>

                    <input type="hidden" name="id_type" value="OFFICE_ID">

                    <label for="id_card"
                           class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-[--color-line-strong] bg-[--color-surface-sunken] px-4 py-6 text-center transition-colors hover:border-navy-400 focus-within:ring-2 focus-within:ring-navy-500"
                           :class="fileName && 'border-navy-400 bg-navy-50'">
                        <span class="flex items-center gap-3 text-[--color-ink]" aria-hidden="true">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3 7.2 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.2L15 3H9Zm3 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6H6Zm7 1.5L18.5 9H13V3.5ZM8 13h8v1.5H8V13Zm0 3h8v1.5H8V16Z"/></svg>
                        </span>
                        <span class="text-sm text-[--color-ink-soft]" x-text="fileName || 'Click to upload your Employee ID (optional)'">Click to upload your Employee ID (optional)</span>
                        <input id="id_card" name="id_card" type="file" accept="image/jpeg,image/png,application/pdf"
                               class="sr-only" @change="pick($event)"
                               aria-describedby="help-idcard"
                               @error('id_card') aria-invalid="true" @enderror>
                    </label>
                    <p id="help-idcard" class="mt-1 text-xs text-[--color-ink-muted]">Optional &middot; Max 5MB &middot; JPG, PNG or PDF</p>
                    <p class="gh-error" x-show="fileError" x-text="fileError" x-cloak role="alert"></p>
                    @error('id_card')<p class="gh-error">{{ $message }}</p>@enderror
                    @if (old('employee_code'))
                        <p class="gh-help">If you had chosen a file, please choose it again &mdash; uploads are not kept after an error.</p>
                    @endif
                </div>
            </div>
        </section>

        {{-- ============ ACCOMMODATION & STAY ============ --}}
        <section class="gh-card p-4 sm:p-5" aria-labelledby="sec-stay">
            <h3 id="sec-stay" class="mb-3 text-base font-semibold text-[--color-ink]">Accommodation &amp; Stay</h3>

            <div class="space-y-4">
                <div>
                    <label for="rooms" class="gh-label gh-required">Number of Rooms Required</label>
                    <div class="flex items-stretch overflow-hidden rounded-lg border border-[--color-line-strong]">
                        <button type="button" @click="rooms = Math.max(1, rooms - 1)" :disabled="rooms <= 1"
                                class="w-14 bg-[--color-surface-sunken] text-xl text-[--color-ink-soft] hover:bg-navy-50 disabled:opacity-40"
                                aria-label="Fewer rooms">&minus;</button>
                        <input id="rooms" name="rooms" type="number" min="1" max="10" x-model.number="rooms"
                               class="w-full border-x border-[--color-line-strong] py-2.5 text-center text-lg font-semibold text-[--color-ink] focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-navy-500"
                               aria-live="polite">
                        <button type="button" @click="rooms = Math.min(10, rooms + 1)" :disabled="rooms >= 10"
                                class="w-14 bg-[--color-surface-sunken] text-xl text-[--color-ink-soft] hover:bg-navy-50 disabled:opacity-40"
                                aria-label="More rooms">+</button>
                    </div>
                    @error('rooms')<p class="gh-error">{{ $message }}</p>@enderror
                </div>

                <fieldset>
                    <legend class="mb-1.5 text-sm font-semibold text-[--color-ink]">Check-in Date &amp; Time</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="check_in_date" class="gh-label gh-required">Date</label>
                            <input id="check_in_date" name="check_in_date" type="date" class="gh-input" required
                                   min="{{ $today }}" x-model="checkIn" value="{{ old('check_in_date') }}"
                                   @error('check_in_date') aria-invalid="true" @enderror>
                        </div>
                        <div>
                            <label for="check_in_time" class="gh-label gh-required">Time</label>
                            <input id="check_in_time" name="check_in_time" type="time" class="gh-input" required
                                   value="{{ old('check_in_time') }}"
                                   @error('check_in_time') aria-invalid="true" @enderror>
                        </div>
                    </div>
                    @error('check_in_date')<p class="gh-error">{{ $message }}</p>@enderror
                    @error('check_in_time')<p class="gh-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset>
                    <legend class="mb-1.5 text-sm font-semibold text-[--color-ink]">Check-out Date &amp; Time</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="check_out_date" class="gh-label gh-required">Date</label>
                            <input id="check_out_date" name="check_out_date" type="date" class="gh-input" required
                                   :min="checkIn || '{{ $today }}'" value="{{ old('check_out_date') }}"
                                   @error('check_out_date') aria-invalid="true" @enderror>
                        </div>
                        <div>
                            <label for="check_out_time" class="gh-label gh-required">Time</label>
                            <input id="check_out_time" name="check_out_time" type="time" class="gh-input" required
                                   value="{{ old('check_out_time') }}"
                                   @error('check_out_time') aria-invalid="true" @enderror>
                        </div>
                    </div>
                    @error('check_out_date')<p class="gh-error">{{ $message }}</p>@enderror
                    @error('check_out_time')<p class="gh-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="gh-alert gh-alert-info" role="note">
                    <span>
                        Your request goes to your Manager for approval. Rooms are
                        allotted by the Administration afterwards &mdash; submitting does not reserve a room.
                    </span>
                </div>
            </div>
        </section>
    </div>

    {{-- Sticky submit, as in the mobile design. --}}
    <div class="fixed inset-x-0 bottom-0 z-20 border-t border-[--color-line] bg-white/95 px-4 py-3 backdrop-blur"
         style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
        <div class="mx-auto max-w-xl">
            {{-- type="button" on purpose: this opens the Terms & Conditions. Only
                 "Final Submit" inside the dialog, shown once the box is ticked,
                 actually posts the form. Do not change this to type="submit". --}}
            <button type="button" class="gh-btn gh-btn-primary w-full py-3 text-base"
                    @click="openTerms()" :disabled="submitting || fileError !== ''">
                <span x-text="submitting ? 'Submitting…' : 'Submit Requisition'">Submit Requisition</span>
            </button>
        </div>
    </div>

    {{-- Terms & Conditions. A native modal dialog gives focus trapping and
         Escape-to-close; it sits inside the form so the checkbox and the
         Final Submit button belong to it. PublicBookingRequest rejects any
         submission without terms_accepted. --}}
    <dialog x-ref="terms" aria-labelledby="terms-title"
            @close="agreed = false"
            class="m-auto w-[calc(100%-2rem)] max-w-2xl rounded-2xl bg-white p-0 shadow-2xl backdrop:bg-black/50">
        <div class="flex max-h-[90vh] flex-col">
            <div x-ref="termsBody" class="overflow-y-auto px-5 pb-4 pt-5 sm:px-6">
                @include('public.partials.terms')
            </div>

            <div class="space-y-3 border-t border-[--color-line] px-5 py-4 sm:px-6">
                <label class="flex cursor-pointer items-start gap-3 text-sm font-medium text-[--color-ink]">
                    <input type="checkbox" name="terms_accepted" value="1" x-model="agreed"
                           class="mt-0.5 h-5 w-5 shrink-0 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                    <span>Agreed for Terms &amp; Conditions</span>
                </label>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="gh-btn gh-btn-secondary py-2.5" @click="$refs.terms.close()"
                            :disabled="submitting">
                        Cancel
                    </button>
                    <button type="submit" x-show="agreed" x-cloak
                            class="gh-btn gh-btn-primary py-2.5" :disabled="submitting">
                        <span x-text="submitting ? 'Submitting…' : 'Final Submit'">Final Submit</span>
                    </button>
                </div>
            </div>
        </div>
    </dialog>
</form>
@endsection
