<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AuditLogger;
use App\Domain\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Notification templates — ROUTES.md `/admin/email-templates`, PLAN.md Phase 9.
 *
 * The 19 templates are fixed by NotificationEvent, so there is no create or
 * delete: only edit. Three rules are enforced on save, because an edited
 * template goes straight to third-party mail and SMS carriers:
 *
 *   1. only the documented placeholders may be used (NOTIFICATIONS.md section 3);
 *      an unknown one would silently render as nothing
 *   2. nothing that looks like identity data — no Aadhaar reference, no
 *      12-digit number, no document link (SECURITY.md section 2)
 *   3. no active HTML: script, embedded frames, event handlers or javascript:
 *      URLs are refused rather than silently stripped
 */
class EmailTemplateController extends Controller
{
    public const PLACEHOLDERS = [
        'user_name', 'request_no', 'applicant_name', 'purpose',
        'check_in_date', 'check_out_date', 'nights', 'total_members',
        'status', 'remarks', 'more_info_note', 'rooms',
        'action_url', 'institution', 'building', 'title',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $templates = EmailTemplate::all()->keyBy('event_key');

        return view('admin.email-templates.index', [
            'events' => collect(NotificationEvent::cases())->map(fn (NotificationEvent $e) => [
                'event' => $e,
                'template' => $templates->get($e->value),
            ]),
        ]);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('admin.email-templates.edit', [
            'template' => $emailTemplate,
            'event' => NotificationEvent::tryFrom($emailTemplate->event_key),
            'placeholders' => self::PLACEHOLDERS,
            'preview' => $this->preview($emailTemplate),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validator = validator($request->all(), [
            'subject' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:20000'],
            'body_text' => ['nullable', 'string', 'max:20000'],
            'sms_text' => ['nullable', 'string', 'max:320'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validator->after(fn (Validator $v) => $this->guard($v, $request));
        $data = $validator->validate();

        DB::transaction(function () use ($emailTemplate, $data, $request) {
            $emailTemplate->fill([
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'body_text' => $data['body_text'] ?? null,
                'sms_text' => filled($data['sms_text'] ?? null) ? $data['sms_text'] : null,
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);

            $changed = array_values(array_diff(array_keys($emailTemplate->getDirty()), ['updated_at']));
            $emailTemplate->save();

            if ($changed !== []) {
                $this->audit->record($emailTemplate, 'TEMPLATE_UPDATED', $request->user(), metadata: [
                    'event_key' => $emailTemplate->event_key,
                    'fields' => $changed,
                ]);
            }
        });

        return redirect()->route('admin.email-templates.edit', $emailTemplate)->with('success', 'Template saved.');
    }

    private function guard(Validator $v, Request $request): void
    {
        foreach (['subject', 'body_html', 'body_text', 'sms_text'] as $field) {
            $text = (string) $request->input($field, '');

            preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);
            $unknown = array_diff(array_map('strtolower', $m[1]), self::PLACEHOLDERS);
            if ($unknown !== []) {
                $v->errors()->add($field, 'Unknown placeholder: {{'.implode('}}, {{', array_unique($unknown)).'}}.');
            }

            $lower = strtolower($text);
            foreach (['aadhaar', 'aadhar', 'id_proof', 'id proof number', '/documents/'] as $forbidden) {
                if (str_contains($lower, $forbidden)) {
                    $v->errors()->add($field, "Templates must not refer to identity data ('{$forbidden}'): mail and SMS leave the institute's network.");
                }
            }

            if (preg_match('/\d{12}/', preg_replace('/\s+/', '', $text) ?? '')) {
                $v->errors()->add($field, 'Templates must not contain a 12-digit number.');
            }

            if (preg_match('/<\s*(script|iframe|object|embed|form|link|meta|style)\b|\son[a-z]+\s*=|javascript\s*:/i', $text)) {
                $v->errors()->add($field, 'Scripts, embedded content, forms and event handlers are not allowed in templates.');
            }
        }
    }

    /** @return array{subject: string, html: string, text: string, sms: ?string} */
    private function preview(EmailTemplate $t): array
    {
        $sample = [
            'user_name' => 'Asha Verma', 'request_no' => 'REQ/'.now()->format('Y').'/00042',
            'applicant_name' => 'Asha Verma', 'purpose' => 'Training',
            'check_in_date' => now()->addDays(7)->format('d/m/Y'), 'check_out_date' => now()->addDays(9)->format('d/m/Y'),
            'nights' => '2', 'total_members' => '1', 'status' => 'Allotted',
            'remarks' => 'Sample remarks', 'more_info_note' => 'Please attach a legible ID proof.',
            'rooms' => '204', 'action_url' => url('/home'),
            'institution' => (string) config('gh.institution'), 'building' => (string) config('gh.building'),
            'title' => 'Announcement',
        ];

        return [
            'subject' => EmailTemplate::render($t->subject, $sample),
            'html' => EmailTemplate::render($t->body_html, $sample),
            'text' => EmailTemplate::render((string) $t->body_text, $sample),
            'sms' => $t->sms_text ? EmailTemplate::render($t->sms_text, $sample) : null,
        ];
    }
}
