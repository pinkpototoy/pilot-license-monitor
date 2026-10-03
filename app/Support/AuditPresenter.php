<?php

namespace App\Support;

use App\Enums\ComplianceState;
use App\Enums\StudentStatus;
use App\Enums\ValidityStatus;
use App\Models\AuditLog;
use App\Models\CredentialType;
use App\Models\Program;
use Carbon\Carbon;

/** SRS 27: render audit entries as readable sentences. */
final class AuditPresenter
{
    private const ACTIONS = [
        'student.created' => 'added the student record',
        'student.updated' => 'updated the student record',
        'student.deactivated' => 'deactivated the student',
        'student.reactivated' => 'reactivated the student',
        'student.compliance_changed' => 'recalculated compliance',
        'credential.created' => 'recorded a credential',
        'credential.period_corrected' => 'corrected credential details',
        'credential.period_added' => 'recorded a new validity period',
        'credential.status_changed' => 'recalculated credential status',
        'credential.override_applied' => 'applied a status override',
        'credential.override_ended' => 'ended a status override',
        'user.invited' => 'sent an account invitation',
        'user.invitation_accepted' => 'accepted the invitation and set a password',
        'auth.login' => 'signed in',
        'auth.logout' => 'signed out',
        'auth.account_locked' => 'locked the account after failed sign-ins',
        'auth.mfa_enrolled' => 'set up two-step verification',
        'auth.mfa_reset' => 'reset two-step verification',
        'auth.recovery_code_used' => 'used a recovery code',
        'auth.password_reset' => 'reset the password',
    ];

    private const FIELDS = [
        'expiry_date' => 'expiry date', 'issue_date' => 'issue date', 'license_number' => 'license number',
        'current_status' => 'status', 'compliance_state' => 'compliance', 'program_id' => 'program',
        'student_number' => 'student number', 'first_name' => 'first name', 'last_name' => 'last name',
        'middle_name' => 'middle name', 'contact_number' => 'contact number', 'date_of_birth' => 'date of birth',
        'override_status' => 'override', 'valid_until' => 'override end', 'credential_type' => 'credential type',
    ];

    public static function actor(AuditLog $log): string
    {
        return $log->actor ? $log->actor->name.' ('.ucfirst($log->actor_role).')' : 'System';
    }

    public static function action(AuditLog $log): string
    {
        return self::ACTIONS[$log->action] ?? str_replace(['.', '_'], ' ', $log->action);
    }

    /** @return list<string> e.g. "expiry date: 15 Mar 2027 → 15 Sep 2027" */
    public static function changes(AuditLog $log): array
    {
        $out = [];
        foreach ($log->changes ?? [] as $field => $change) {
            $out[] = (self::FIELDS[$field] ?? str_replace('_', ' ', $field)).': '
                .self::value($change['old'] ?? null, $field).' → '.self::value($change['new'] ?? null, $field);
        }

        return $out;
    }

    private static function value(mixed $v, string $field = ''): string
    {
        if ($v === null || $v === '') {
            return '(empty)';
        }
        // Show names and labels, never internal IDs or codes.
        $label = match ($field) {
            'program_id' => self::programName((int) $v),
            'current_status', 'override_status' => ValidityStatus::tryFrom((string) $v)?->label(),
            'compliance_state' => ComplianceState::tryFrom((string) $v)?->label(),
            'status' => StudentStatus::tryFrom((string) $v)?->label(),
            'credential_type' => CredentialType::where('code', $v)->value('name'),
            default => null,
        };
        if ($label !== null) {
            return $label;
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return Carbon::parse($v)->format('d M Y');
        }
        if (is_string($v) && preg_match('/^[a-z_]+$/', $v)) {
            return ucwords(str_replace('_', ' ', $v));
        }

        return is_scalar($v) ? (string) $v : json_encode($v);
    }

    private static function programName(int $id): string
    {
        static $names = null;
        $names ??= Program::pluck('name', 'id')->all();

        return $names[$id] ?? "Program #{$id}";
    }
}
