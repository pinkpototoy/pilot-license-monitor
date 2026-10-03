<?php

/*
| Pilot License Monitor — domain configuration.
| Values marked "confirm" come from SRS items flagged
| "Requires Stakeholder/Authority Confirmation" and must be verified in Phase 0.
*/

return [
    // NFR-017: all date logic runs in the organization's time zone; timestamps are stored in UTC.
    'timezone' => env('SPLMS_TIMEZONE', 'Asia/Manila'),

    // Section 13.1: a credential is valid through the end of its expiry date (confirm).
    'expiry_date_is_valid_day' => true,

    // Default Expiring Soon window when a credential type does not set one.
    'default_expiring_soon_days' => 30,

    // FR-005 / NFR: account lockout.
    'lockout' => [
        'max_attempts' => 5,
        'minutes' => 15,
    ],

    // FR-006: idle timeouts in minutes, absolute session lifetime in hours.
    'session' => [
        'idle_minutes_staff' => 15,
        'idle_minutes_student' => 30,
        'absolute_hours' => 12,
    ],

    // NFR-005: password policy.
    'password_min_length' => 12,

    // BR-043: minimum number of active Admin accounts.
    'min_active_admins' => 2,

    // Phase 3 — documents (BR-023). Per-type limits live on document_types.
    'documents' => [
        'max_case_mb' => 50,
        'allowed' => [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ],
        // 'clamav' (clamd over TCP/socket) or 'signature' (development: detects the EICAR test file only).
        'scanner' => env('SPLMS_SCANNER', 'signature'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => 30,
        ],
    ],

    // BR-027: abandoned drafts.
    'renewals' => [
        'draft_warning_days' => 45,
        'draft_cancel_days' => 60,
        'draft_idle_reminder_days' => 7,
        'correction_idle_reminder_days' => 5,
    ],

    // FR-056: retry delays in minutes after attempts 1..4; the 5th failure is final.
    'notifications' => [
        'retry_minutes' => [5, 30, 120, 720],
        'max_attempts' => 5,
        'sms_enabled' => env('SPLMS_SMS_ENABLED', false),
    ],

    // Section 28.
    'import' => [
        'rollback_days' => 7,
        'max_rows' => 10000,
    ],
];
