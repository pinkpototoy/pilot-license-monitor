<?php

namespace App\Domain\Records;

use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\ComplianceEngine;
use App\Domain\DomainRuleViolation;
use App\Domain\Notifications\NotificationService;
use App\Domain\Renewals\RenewalService;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\RenewalCase;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** UC-03, UC-18 — student records (FR-010 to FR-015, BR-001 to BR-004). */
class StudentService
{
    public const EDITABLE = [
        'student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth',
        'email', 'contact_number', 'program_id', 'cohort',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ComplianceEngine $engine,
    ) {}

    public function create(array $data, User $actor, ?int $importBatchId = null): Student
    {
        $data = $this->normalize($data);
        $this->assertUnique($data);

        return DB::transaction(function () use ($data, $actor, $importBatchId) {
            $student = new Student(array_intersect_key($data, array_flip(self::EDITABLE)));
            $student->forceFill([
                'status' => StudentStatus::Active,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'import_batch_id' => $importBatchId,
            ])->save();

            $this->audit->record('student.created', 'student', $student->id, $student->id,
                AuditLogger::diff([], $student->only(self::EDITABLE)),
                $importBatchId ? "Import batch #{$importBatchId}" : null, $actor);

            $this->engine->evaluateStudent($student->refresh());

            return $student;
        });
    }

    public function update(Student $student, array $data, User $actor, ?string $reason = null): Student
    {
        $data = array_intersect_key($this->normalize($data), array_flip(self::EDITABLE));
        $this->assertUnique($data, $student->id);

        return DB::transaction(function () use ($student, $data, $actor, $reason) {
            $before = $student->only(self::EDITABLE);
            $student->fill($data);
            $student->updated_by = $actor->id;
            $changes = AuditLogger::diff($before, $student->only(self::EDITABLE));

            if ($changes === []) {
                return $student;
            }

            $student->save();
            $this->audit->record('student.updated', 'student', $student->id, $student->id, $changes, $reason, $actor);

            if (array_key_exists('program_id', $changes)) {
                $this->engine->evaluateStudent($student->refresh());
            }

            return $student;
        });
    }

    /**
     * FR-015 / BR-004 / EC-12: stop monitoring, block login, revoke sessions, keep all records.
     * Open renewal cases are cancelled and pending notifications withdrawn.
     */
    public function deactivate(Student $student, User $actor, string $reason): Student
    {
        if (trim($reason) === '') {
            throw DomainRuleViolation::on('reason', 'A reason is required to deactivate a student.');
        }
        if ($student->status === StudentStatus::Deactivated) {
            return $student;
        }

        return DB::transaction(function () use ($student, $actor, $reason) {
            $old = $student->status;
            $student->forceFill([
                'status' => StudentStatus::Deactivated,
                'deactivated_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            if ($student->user) {
                $student->user->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();
                DB::table('sessions')->where('user_id', $student->user_id)->delete();
            }

            // EC-12: open renewals are cancelled and queued messages withdrawn.
            $renewals = app(RenewalService::class);
            RenewalCase::open()->whereHas('credential', fn ($q) => $q->where('student_id', $student->id))
                ->get()->each(fn ($case) => $renewals->cancel($case, $actor, 'Student deactivated: '.$reason));
            app(NotificationService::class)->cancelPendingForStudent($student);

            $this->audit->record('student.deactivated', 'student', $student->id, $student->id,
                ['status' => ['old' => $old->value, 'new' => StudentStatus::Deactivated->value]], $reason, $actor);

            $this->engine->evaluateStudent($student->refresh());

            return $student;
        });
    }

    public function reactivate(Student $student, User $actor, string $reason): Student
    {
        return DB::transaction(function () use ($student, $actor, $reason) {
            $old = $student->status;
            $this->assertUnique(['email' => $student->email], $student->id);
            $student->forceFill(['status' => StudentStatus::Active, 'deactivated_at' => null, 'updated_by' => $actor->id])->save();
            $this->audit->record('student.reactivated', 'student', $student->id, $student->id,
                ['status' => ['old' => $old->value, 'new' => StudentStatus::Active->value]], $reason, $actor);
            $this->engine->evaluateStudent($student->refresh());

            return $student;
        });
    }

    /**
     * FR-012 / EC-03: probable duplicates — same name + birth date, same contact number,
     * or same email on an inactive record. Warnings only; the user decides.
     *
     * @return Collection<int, Student>
     */
    public function possibleDuplicates(array $data, ?int $ignoreId = null): Collection
    {
        $data = $this->normalize($data);

        return Student::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->where(function ($q) use ($data) {
                if (! empty($data['first_name']) && ! empty($data['last_name']) && ! empty($data['date_of_birth'])) {
                    $q->orWhere(fn ($w) => $w
                        ->whereRaw('lower(first_name) = lower(?)', [$data['first_name']])
                        ->whereRaw('lower(last_name) = lower(?)', [$data['last_name']])
                        ->whereDate('date_of_birth', $data['date_of_birth']));
                }
                if (! empty($data['contact_number'])) {
                    $q->orWhere('contact_number', $data['contact_number']);
                }
                if (! empty($data['email'])) {
                    $q->orWhereRaw('lower(email) = ?', [$data['email']]);
                }
                $q->orWhereRaw('false');
            })
            ->limit(5)->get();
    }

    private function assertUnique(array $data, ?int $ignoreId = null): void
    {
        $errors = [];

        // BR-001: unique forever, never reused.
        if (! empty($data['student_number']) && Student::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->whereRaw('lower(student_number) = lower(?)', [$data['student_number']])->exists()) {
            $errors['student_number'] = 'BR-001: This student number is already used by another record (student numbers are never reused).';
        }

        // FR-011: unique email among active students.
        if (! empty($data['email']) && Student::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->whereNotIn('status', ['deactivated', 'withdrawn', 'graduated'])
            ->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->exists()) {
            $errors['email'] = 'FR-011: Another active student already uses this email address.';
        }

        if ($errors) {
            throw new DomainRuleViolation($errors);
        }
    }

    /** Section 28 cleansing rules applied on every save, not only on import. */
    private function normalize(array $data): array
    {
        foreach (['student_number', 'first_name', 'middle_name', 'last_name', 'email', 'contact_number', 'cohort'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = $data[$k] === null ? null : (preg_replace('/\s+/', ' ', trim((string) $data[$k])) ?: null);
            }
        }
        if (isset($data['student_number'])) {
            $data['student_number'] = mb_strtoupper($data['student_number']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if (isset($data['contact_number'])) {
            $data['contact_number'] = PhoneNumber::normalize($data['contact_number']);
        }

        return $data;
    }
}
