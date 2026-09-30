<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the public "Room & Stay Details" requisition form.
 *
 * No session exists here, so the employee is identified by Employee ID / PPO
 * No. plus the mobile number on their account. That match is done in the
 * controller, after these field rules pass, so a failed match produces one
 * generic message regardless of which half was wrong.
 */
class PublicBookingRequest extends FormRequest
{
    /**
     * ID cards accepted on the public form, mapped to request_documents.doc_type.
     * Employee ID cards are stored under the existing OFFICE_ID proof type.
     */
    public const ID_TYPES = [
        'OFFICE_ID' => 'Employee ID',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // DEMO MODE: the public requisition form intentionally applies little
        // blocking validation; the controller fills in sensible defaults for
        // most missing fields. Two rules are NOT optional:
        //   - a valid 10-digit contact mobile, and
        //   - agreement to the Terms & Conditions (the dialog's checkbox).
        return [
            'employee_code' => ['nullable', 'string', 'max:30'],
            'contact_mobile' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'purpose' => ['nullable', 'string'],
            'training_programme' => ['nullable', 'string', 'max:150'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'employee_name' => ['nullable', 'string', 'max:120'],
            'id_type' => ['nullable', 'string'],
            'id_card' => ['nullable', 'file'],
            'rooms' => ['nullable', 'integer'],
            'check_in_date' => ['nullable', 'string'],
            'check_in_time' => ['nullable', 'string'],
            'check_out_date' => ['nullable', 'string'],
            'check_out_time' => ['nullable', 'string'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_code' => 'Employee ID / PPO No.',
            'contact_mobile' => 'contact number',
            'purpose' => 'type of visit',
            'employee_name' => 'employee name',
            'id_type' => 'ID document type',
            'id_card' => 'Employee ID',
            'rooms' => 'number of rooms',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_mobile.required' => 'Enter your contact number.',
            'contact_mobile.regex' => 'Enter a valid 10-digit mobile number.',
            'terms_accepted.accepted' => 'You must agree to the Terms & Conditions before submitting.',
        ];
    }
}
