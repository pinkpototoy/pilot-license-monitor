<?php

namespace App\Domain\Notifications;

use App\Models\NotificationTemplate;

/** FR-059 — {{placeholder}} substitution. Plain text only; unknown placeholders render empty. */
final class TemplateRenderer
{
    public const PLACEHOLDERS = [
        'student_first_name' => 'Student first name',
        'student_name' => 'Student full name',
        'student_number' => 'Student number',
        'credential_name' => 'Credential type, e.g. Medical Certificate',
        'license_number' => 'License or certificate number',
        'expiry_date' => 'Expiry date, e.g. 15 Mar 2027',
        'days_text' => 'e.g. "in 30 days", "today", "7 days ago"',
        'action_line' => 'What to do next (context-dependent)',
        'case_status' => 'Renewal case status',
        'reasons' => 'Rejection reasons, one per line',
        'app_url' => 'Link to the system',
    ];

    /** @return array{subject: string, body: string} */
    public function render(string $code, string $channel, array $vars): array
    {
        $tpl = NotificationTemplate::current($code, $channel);
        $vars['app_url'] ??= rtrim((string) config('app.url'), '/');
        $subject = $tpl?->subject ?? ucfirst(str_replace('_', ' ', $code));
        $body = $tpl?->body ?? '{{action_line}}';

        return ['subject' => $this->fill($subject, $vars), 'body' => trim($this->fill($body, $vars))];
    }

    public function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);
    }
}
