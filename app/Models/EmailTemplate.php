<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class EmailTemplate extends Model
{
    protected $fillable = [
        'event_key', 'subject', 'body_html', 'body_text',
        'sms_text', 'placeholders', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Replace {{token}} placeholders.
     *
     * An unknown placeholder renders as empty and logs a warning rather than
     * leaking a raw {{token}} to a recipient — NOTIFICATIONS.md section 3. A
     * government applicant receiving "Dear {{user_name}}" is worse than receiving
     * a slightly terse sentence.
     *
     * @param  array<string, string|int|null>  $tokens
     */
    public static function render(string $text, array $tokens): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $m) use ($tokens): string {
                $key = strtolower($m[1]);

                if (! array_key_exists($key, $tokens)) {
                    Log::warning('Unknown notification placeholder', ['token' => $key]);

                    return '';
                }

                return (string) ($tokens[$key] ?? '');
            },
            $text,
        );
    }
}
