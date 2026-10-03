<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SRS 27 — writes append-only audit entries inside the caller's transaction, so a
 * change can never exist without its audit record. Each row stores the previous
 * row's hash (hash chain); verifyChain() detects edits made outside the application.
 */
class AuditLogger
{
    /** Keys that must never be written to the audit log. */
    private const REDACTED = ['password', 'mfa_secret', 'mfa_recovery_codes', 'remember_token'];

    private const CHAIN_LOCK_KEY = 727001;

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?int $studentId = null,
        ?array $changes = null,
        ?string $remarks = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($action, $entityType, $entityId, $studentId, $changes, $remarks, $actor) {
            // Serialize chain writers; the lock is held until the outer transaction commits.
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::CHAIN_LOCK_KEY]);

            $prevHash = DB::table('audit_logs')->orderByDesc('id')->value('row_hash');

            $entry = [
                'occurred_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
                'actor_user_id' => $actor?->id,
                'actor_role' => $actor?->role?->value ?? 'system',
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'student_id' => $studentId,
                'changes' => $changes === null ? null : $this->redact($changes),
                'remarks' => $remarks,
                'ip_address' => $this->request?->ip(),
                'user_agent' => $this->request ? mb_substr((string) $this->request->userAgent(), 0, 500) : null,
                'request_id' => $this->request?->attributes->get('request_id'),
                'prev_hash' => $prevHash,
            ];
            $entry['row_hash'] = self::hash($entry);

            return AuditLog::create($entry);
        });
    }

    /**
     * Build a field-level diff between two attribute arrays (only changed keys).
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $new) {
            $old = $before[$key] ?? null;
            if (self::normalize($old) !== self::normalize($new)) {
                $changes[$key] = ['old' => self::normalize($old), 'new' => self::normalize($new)];
            }
        }

        return $changes;
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int} */
    public function verifyChain(): array
    {
        $prev = null;
        $checked = 0;
        foreach (DB::table('audit_logs')->orderBy('id')->cursor() as $row) {
            $entry = [
                'occurred_at' => $row->occurred_at,
                'actor_user_id' => $row->actor_user_id,
                'actor_role' => $row->actor_role,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'student_id' => $row->student_id,
                'changes' => $row->changes === null ? null : json_decode($row->changes, true),
                'remarks' => $row->remarks,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'request_id' => $row->request_id,
                'prev_hash' => $row->prev_hash,
            ];
            if ($row->prev_hash !== $prev || self::hash($entry) !== $row->row_hash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $row->id];
            }
            $prev = $row->row_hash;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }

    private static function hash(array $entry): string
    {
        $canonical = $entry;
        // Timestamps round-trip through PostgreSQL; hash them at second precision in UTC.
        $canonical['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $entry['occurred_at']));
        $canonical['changes'] = $entry['changes'] === null ? null : self::sortKeys($entry['changes']);
        foreach (['actor_user_id', 'entity_id', 'student_id'] as $k) {
            $canonical[$k] = $entry[$k] === null ? null : (int) $entry[$k];
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sortKeys(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortKeys($v);
            }
        }

        return $value;
    }

    private function redact(array $changes): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $changes)) {
                $changes[$key] = ['old' => '[redacted]', 'new' => '[redacted]'];
            }
        }

        return $changes;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === '' ? null : $value;
    }
}
