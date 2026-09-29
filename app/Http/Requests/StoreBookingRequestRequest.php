<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Enums\VisitPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating or updating a booking request (SCREENS.md 3.2).
 *
 * Conditional fields are enforced server-side. The form hides irrelevant fields
 * with Alpine, but a hidden field is a convenience, not a control: a crafted POST
 * must still be rejected.
 */
class StoreBookingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('request.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = config('gh.upload.max_kb');
        $mimes = implode(',', config('gh.upload.mimes'));

        return [
            'purpose' => ['required', Rule::enum(VisitPurpose::class)],

            // Required only for their own purpose; see withValidator() below.
            'training_programme' => ['nullable', 'string', 'max:150'],
            'host_employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'guest_of_name' => ['nullable', 'string', 'max:120'],

            // A stay cannot begin in the past, and must be at least one night.
            'check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],

            'total_members' => ['required', 'integer', 'min:1', 'max:50'],
            'preferred_room_type_id' => ['nullable', 'integer'],

            'contact_mobile' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'contact_email' => ['required', 'email:rfc', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            // --- occupants
            'occupants' => ['required', 'array', 'min:1', 'max:50'],
            'occupants.*.name' => ['required', 'string', 'max:120'],
            'occupants.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'occupants.*.gender' => ['nullable', Rule::in(['M', 'F', 'O'])],
            'occupants.*.relation' => ['nullable', 'string', 'max:60'],
            'occupants.*.id_proof_type' => ['nullable', Rule::in(['AADHAAR', 'PAN', 'PASSPORT', 'OFFICE_ID', 'OTHER'])],
            'occupants.*.id_proof_number' => ['nullable', 'string', 'max:40'],

            // --- documents
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => ['file', "mimes:{$mimes}", "max:{$maxKb}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_in_date.after_or_equal' => 'The check-in date cannot be in the past.',
            'check_out_date.after' => 'The check-out date must be later than the check-in date.',
            'contact_mobile.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'documents.required' => 'An identity proof must be attached.',
            'occupants.required' => 'Enter the details of at least one occupant.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $purpose = VisitPurpose::tryFrom((string) $this->input('purpose'));

            if ($purpose === null) {
                return;
            }

            if ($purpose->needsTrainingProgramme() && blank($this->input('training_programme'))) {
                $v->errors()->add('training_programme',
                    'Enter the name of the training programme you are attending.');
            }

            if ($purpose->needsHost()
                && blank($this->input('host_employee_id'))
                && blank($this->input('guest_of_name'))) {
                $v->errors()->add('host_employee_id',
                    'Name the NADT employee hosting you.');
            }

            // The occupant list must account for every declared member. Without
            // this, a request for 4 people could arrive with one name on it and
            // the guest house would not know who is arriving.
            $declared = (int) $this->input('total_members');
            $listed = is_array($this->input('occupants')) ? count($this->input('occupants')) : 0;

            if ($declared !== $listed) {
                $v->errors()->add('occupants', sprintf(
                    'You declared %d member(s) but entered %d occupant(s). These must match.',
                    $declared,
                    $listed,
                ));
            }

            // A stay longer than 30 nights is almost certainly a data-entry
            // error, and is flagged rather than silently accepted.
            if ($this->filled(['check_in_date', 'check_out_date'])) {
                $nights = Carbon::parse($this->input('check_in_date'))
                    ->diffInDays(Carbon::parse($this->input('check_out_date')));

                if ($nights > 30) {
                    $v->errors()->add('check_out_date',
                        'A stay longer than 30 nights requires a separate sanction. Please contact the administrator.');
                }
            }
        });
    }
}
