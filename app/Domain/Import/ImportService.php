<?php

namespace App\Domain\Import;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Domain\Compliance\ComplianceEngine;
use App\Domain\Compliance\ValidityCalculator;
use App\Domain\DomainRuleViolation;
use App\Domain\Records\CredentialService;
use App\Domain\Records\PhoneNumber;
use App\Domain\Records\StudentService;
use App\Enums\PeriodSource;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SRS 28 / UC-04 — Google Sheets (exported as CSV) and later CSV batches.
 * Prepare = parse + validate every row, nothing written to live tables.
 * Commit (Admin) = rows go through the normal services, so every business rule and audit entry applies.
 */
class ImportService
{
    /** Header aliases seen in real spreadsheets → canonical field. */
    public const HEADERS = [
        'student_number' => ['student number', 'student no', 'student id', 'student no.', 'id number', 'id no', 'student_number'],
        'first_name' => ['first name', 'given name', 'firstname', 'first_name'],
        'middle_name' => ['middle name', 'mi', 'middle initial', 'middle_name'],
        'last_name' => ['last name', 'surname', 'family name', 'lastname', 'last_name'],
        'full_name' => ['name', 'full name', 'student name', 'full_name'],
        'email' => ['email', 'email address', 'e-mail', 'e mail'],
        'contact_number' => ['contact', 'contact number', 'contact no', 'mobile', 'mobile number', 'phone', 'phone number', 'contact_number'],
        'date_of_birth' => ['date of birth', 'birth date', 'birthdate', 'dob', 'birthday', 'date_of_birth'],
        'program' => ['program', 'course', 'program code', 'program_code'],
        'cohort' => ['cohort', 'batch', 'class'],
        'credential_type' => ['license type', 'credential type', 'type', 'credential', 'license', 'credential_type', 'credential_type_code'],
        'license_number' => ['license number', 'license no', 'license no.', 'license #', 'certificate number', 'certificate no', 'license_number'],
        'issue_date' => ['date issued', 'issue date', 'issued', 'issued on', 'issue_date'],
        'expiry_date' => ['expiry', 'expiry date', 'expiration', 'expiration date', 'valid until', 'expires', 'expiry_date'],
        'sheet_status' => ['status', 'license status', 'sheet_status'],
        'remarks' => ['remarks', 'notes', 'comment', 'comments'],
    ];

    /** Spelling variants of credential types found in spreadsheets (section 28 cleansing). */
    public const TYPE_VARIANTS = [
        'SPL' => ['spl', 'student pilot license', 'student pilot licence', 'student pilot lic', 'student pilot lic.', 'student pilot', 'student pilot permit'],
        'MED' => ['med', 'medical', 'medical certificate', 'medical cert', 'class 2 medical', 'class ii medical', 'medical class 2', 'mc'],
        'RTP' => ['rtp', 'radio telephony permit', 'radio permit', 'radiotelephony permit', 'rt permit'],
    ];

    public const TEMPLATE_COLUMNS = ['student_number', 'first_name', 'middle_name', 'last_name', 'email', 'contact_number',
        'date_of_birth', 'program', 'cohort', 'credential_type', 'license_number', 'issue_date', 'expiry_date', 'sheet_status', 'remarks'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StudentService $students,
        private readonly CredentialService $credentials,
        private readonly ValidityCalculator $calculator,
    ) {}

    public function prepare(UploadedFile $file, string $convention, User $actor): ImportBatch
    {
        if (! in_array($convention, ['iso', 'dmy', 'mdy'], true)) {
            throw DomainRuleViolation::on('date_convention', 'Choose how dates are written in the sheet.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt'], true)) {
            throw DomainRuleViolation::on('file', 'Upload a CSV file. In Google Sheets: File → Download → Comma-separated values (.csv).');
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $key = 'originals/'.now()->format('Ymd_His').'_'.Str::random(8).'.csv';
        Storage::disk('imports')->putFileAs('originals', $file, basename($key));   // kept as evidence (section 28.1)

        [$headers, $records] = $this->readCsv(Storage::disk('imports')->path($key));
        $map = $this->mapHeaders($headers);
        if (! isset($map['student_number'])) {
            Storage::disk('imports')->delete($key);
            throw DomainRuleViolation::on('file', 'No student number column found. Columns seen: '.implode(', ', array_slice($headers, 0, 12)).'. Download the template to see the expected names.');
        }
        if (count($records) > config('splms.import.max_rows')) {
            throw DomainRuleViolation::on('file', 'This file has more than '.config('splms.import.max_rows').' rows. Split it into smaller files.');
        }

        return DB::transaction(function () use ($file, $convention, $actor, $sha, $key, $records, $map) {
            $batch = ImportBatch::create([
                'source_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_sha256' => $sha,
                'uploaded_by' => $actor->id, 'status' => 'validating', 'date_convention' => $convention,
                'original_file_key' => $key, 'row_count' => count($records),
            ]);
            $seenNumbers = [];
            $valid = $errors = 0;
            foreach ($records as $i => $raw) {
                $data = [];
                foreach ($map as $field => $col) {
                    $data[$field] = trim((string) ($raw[$col] ?? ''));
                }
                [$status, $messages, $clean] = $this->validateRow($data, $convention, $seenNumbers);
                $status === 'error' ? $errors++ : $valid++;
                ImportRow::create(['batch_id' => $batch->id, 'row_number' => $i + 2, 'raw_data' => $raw + ['_clean' => $clean],
                    'status' => $status, 'messages' => $messages]);
            }
            $batch->update(['status' => 'ready', 'valid_count' => $valid, 'error_count' => $errors]);
            $this->audit->record('import.prepared', 'import_batch', $batch->id, null,
                ['rows' => ['old' => null, 'new' => count($records)], 'errors' => ['old' => null, 'new' => $errors]], $batch->source_name, $actor);

            return $batch;
        });
    }

    /** @return array{0: string, 1: list<array{level: string, text: string}>, 2: array} */
    public function validateRow(array $d, string $convention, array &$seen): array
    {
        $msgs = [];
        // Closures capture $msgs by reference (arrow functions would capture a copy).
        $err = function (string $t) use (&$msgs) {
            $msgs[] = ['level' => 'error', 'text' => $t];
        };
        $warn = function (string $t) use (&$msgs) {
            $msgs[] = ['level' => 'warning', 'text' => $t];
        };
        $info = function (string $t) use (&$msgs) {
            $msgs[] = ['level' => 'info', 'text' => $t];
        };
        $today = Clock::today();

        $clean = ['student_number' => mb_strtoupper(preg_replace('/\s+/', '', $d['student_number'] ?? ''))];
        if ($clean['student_number'] === '') {
            $err('Missing student number.');
        }

        // Names: explicit columns, or a single full-name column split with a flag.
        $first = $d['first_name'] ?? '';
        $last = $d['last_name'] ?? '';
        if (($first === '' || $last === '') && ($d['full_name'] ?? '') !== '') {
            [$first, $middle, $last] = $this->splitName($d['full_name']);
            $clean['middle_name'] = $middle;
            $info("Name split as first \"{$first}\", last \"{$last}\"; check it.");
        }
        $clean['first_name'] = $first;
        $clean['last_name'] = $last;
        $clean['middle_name'] ??= ($d['middle_name'] ?? '') ?: null;
        if ($first === '' || $last === '') {
            $err('Missing first or last name.');
        }

        $email = mb_strtolower($d['email'] ?? '');
        if ($email === '') {
            $err('Missing email (needed for reminders and the account invitation).');
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err("\"{$email}\" is not a valid email address.");
        }
        $clean['email'] = $email;
        $clean['contact_number'] = PhoneNumber::normalize($d['contact_number'] ?? null);
        if (! $clean['contact_number']) {
            $warn('No contact number.');
        }
        $clean['cohort'] = ($d['cohort'] ?? '') ?: null;

        $clean['date_of_birth'] = null;
        if (($d['date_of_birth'] ?? '') !== '') {
            [$dob, $amb] = $this->parseDate($d['date_of_birth'], $convention);
            $dob ? $clean['date_of_birth'] = $dob->toDateString() : $warn("Date of birth \"{$d['date_of_birth']}\" not understood; left empty.");
            $amb && $warn("Date of birth \"{$d['date_of_birth']}\" could be read two ways; read as ".$dob->format('d M Y').'.');
        }

        $clean['program_id'] = null;
        if (($d['program'] ?? '') !== '') {
            $p = Program::whereRaw('lower(code) = ?', [mb_strtolower($d['program'])])->orWhereRaw('lower(name) = ?', [mb_strtolower($d['program'])])->first();
            $p ? $clean['program_id'] = $p->id : $warn("Program \"{$d['program']}\" not recognised; the student is imported without a program (shown as a data issue).");
        } else {
            $warn('No program; the student will show as a data issue until one is assigned.');
        }

        // Existing student with this number → the credential is added to it.
        $existing = $clean['student_number'] ? Student::whereRaw('lower(student_number) = lower(?)', [$clean['student_number']])->first() : null;
        if ($existing) {
            $info("Student {$existing->student_number} already exists ({$existing->fullName()}); only the credential will be added.");
            if ($last !== '' && mb_strtolower($existing->last_name) !== mb_strtolower($last)) {
                $warn("Name differs from the existing record ({$existing->fullName()}). Check this is the same person.");
            }
            $clean['existing_student_id'] = $existing->id;
        } elseif ($clean['student_number'] && ! isset($seen['student'][$clean['student_number']])) {
            $dupes = app(StudentService::class)->possibleDuplicates([
                'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $clean['date_of_birth'],
                'email' => $email, 'contact_number' => $clean['contact_number'],
            ]);
            if ($dupes->isNotEmpty()) {
                $warn('Possible duplicate of existing student '.$dupes->map(fn ($s) => "{$s->student_number} ({$s->fullName()})")->implode(', ').'.');
            }
        }
        if ($clean['student_number']) {
            $seen['student'][$clean['student_number']] = true;
        }

        // Credential part (optional: a row may hold only a student).
        $hasCred = ($d['credential_type'] ?? '') !== '' || ($d['license_number'] ?? '') !== '' || ($d['expiry_date'] ?? '') !== '';
        $clean['credential'] = null;
        if ($hasCred) {
            $type = $this->resolveType($d['credential_type'] ?? '');
            if (! $type) {
                $err('Unknown credential type "'.($d['credential_type'] ?? '').'". Use one of: '.CredentialType::where('active', true)->pluck('name')->implode(', ').'.');
            }
            $number = ($d['license_number'] ?? '') !== '' ? mb_strtoupper(preg_replace('/\s+/', '', $d['license_number'])) : null;
            [$issue, $ambI] = ($d['issue_date'] ?? '') !== '' ? $this->parseDate($d['issue_date'], $convention) : [null, false];
            [$expiry, $ambE] = ($d['expiry_date'] ?? '') !== '' ? $this->parseDate($d['expiry_date'], $convention) : [null, false];

            if (($d['issue_date'] ?? '') !== '' && ! $issue) {
                $err("Issue date \"{$d['issue_date']}\" is not a date.");
            }
            if (($d['expiry_date'] ?? '') !== '' && ! $expiry) {
                $err("Expiry date \"{$d['expiry_date']}\" is not a date.");
            }
            $ambI && $warn("Issue date \"{$d['issue_date']}\" could be read two ways; read as ".$issue->format('d M Y').'.');
            $ambE && $warn("Expiry date \"{$d['expiry_date']}\" could be read two ways; read as ".$expiry->format('d M Y').'.');
            if ($issue && $expiry && ! $expiry->gt($issue)) {
                $err('BR-011: Expiry date is not after the issue date.');
            }
            if ($issue && $issue->gt($today->addDay())) {
                $err('BR-012: Issue date is in the future.');
            }
            if ($type?->expires && ! $expiry && ($d['expiry_date'] ?? '') === '') {
                $warn('No expiry date: imported as Incomplete Data (EC-01).');
            }
            if ($expiry && $expiry->gt($today->addYears(10))) {
                $warn('Expiry is more than 10 years away; check the year.');
            }
            if ($type && $number && $type->number_pattern && ! $type->acceptsNumber($number)) {
                $err("License number {$number} doesn't match the format set for {$type->name}.");
            }
            $owner = null;
            if ($type && $number) {
                $owner = Credential::where('credential_type_id', $type->id)->whereRaw('upper(license_number) = ?', [$number])->with('student')->first();
                if ($owner && $owner->student->student_number !== $clean['student_number']) {
                    $err("BR-014: {$type->code} {$number} already belongs to {$owner->student->student_number}.");
                } elseif ($owner) {
                    $err("Already imported: {$owner->student->student_number} has {$type->code} {$number}. Skip this row.");
                }
                $k = $type->id.'|'.$number;
                if (isset($seen['license'][$k])) {
                    $err("Duplicate in this file: {$type->code} {$number} also appears on row {$seen['license'][$k]}.");
                }
                $seen['license'][$k] = ($seen['row'] ?? 0) + 2;
            }
            if ($type && $existing && ! $owner && $existing->currentCredentials()->where('credential_type_id', $type->id)->exists()) {
                $err("BR-015: {$existing->student_number} already has a current {$type->name}.");
            }
            if ($type && $clean['student_number']) {
                $sk = $clean['student_number'].'|'.$type->id;
                if (isset($seen['student_type'][$sk])) {
                    $err("BR-015: this file already gives {$clean['student_number']} a {$type->name} on another row.");
                }
                $seen['student_type'][$sk] = true;
            }

            // Section 28.2: the sheet's own status is not imported; mismatches are reported.
            if ($type && ($d['sheet_status'] ?? '') !== '') {
                $computed = $this->calculator->calculate($type->expires, $type->expiring_soon_days, $number, $issue, $expiry, $today);
                $norm = fn ($x) => preg_replace('/[^a-z]/', '', mb_strtolower($x));
                if ($norm($d['sheet_status']) !== $norm($computed->label()) && $norm($d['sheet_status']) !== $norm($computed->value)) {
                    $info("Sheet said \"{$d['sheet_status']}\"; the dates give \"{$computed->label()}\".");
                }
            }
            $clean['credential'] = ['type_id' => $type?->id, 'license_number' => $number,
                'issue_date' => $issue?->toDateString(), 'expiry_date' => $expiry?->toDateString()];
        }
        $seen['row'] = ($seen['row'] ?? 0) + 1;
        $clean['remarks'] = ($d['remarks'] ?? '') ?: null;

        $levels = array_column($msgs, 'level');
        $status = in_array('error', $levels, true) ? 'error' : (in_array('warning', $levels, true) ? 'warning' : 'valid');

        return [$status, $msgs, $clean];
    }

    /** Admin commits the valid and warning rows of a prepared batch. */
    public function commit(ImportBatch $batch, User $admin): ImportBatch
    {
        if ($admin->role !== Role::Admin) {
            throw DomainRuleViolation::on('batch', 'Only an Admin can commit an import (Staff prepare it).');
        }
        if ($batch->status !== 'ready') {
            throw DomainRuleViolation::on('batch', 'This import is '.$batch->status.' and cannot be committed.');
        }
        $imported = 0;
        $failed = 0;
        foreach ($batch->rows()->whereIn('status', ['valid', 'warning'])->orderBy('row_number')->get() as $row) {
            $clean = $row->raw_data['_clean'];
            try {
                DB::transaction(function () use ($row, $clean, $batch, $admin, &$imported) {
                    $created = [];
                    $student = isset($clean['existing_student_id']) ? Student::find($clean['existing_student_id'])
                        : Student::whereRaw('lower(student_number) = lower(?)', [$clean['student_number']])->first();
                    if (! $student) {
                        $student = $this->students->create(Arr::only($clean, StudentService::EDITABLE), $admin, $batch->id);
                        $created['student_id'] = $student->id;
                    }
                    if ($c = $clean['credential']) {
                        $credential = $this->credentials->record($student, CredentialType::findOrFail($c['type_id']), $c['license_number'],
                            $c['issue_date'], $c['expiry_date'], $admin, PeriodSource::Import, confirmed: true, importBatchId: $batch->id);
                        $created['credential_id'] = $credential->id;
                    }
                    $row->update(['status' => 'imported', 'created_entity_ids' => $created]);
                    $imported++;
                });
            } catch (Throwable $e) {
                // Never silent: the row is marked with the reason (section 31).
                $msg = $e instanceof DomainRuleViolation ? $e->getMessage() : 'Could not import: '.mb_substr($e->getMessage(), 0, 300);
                $row->update(['status' => 'error', 'messages' => array_merge($row->messages ?? [], [['level' => 'error', 'text' => $msg]])]);
                $failed++;
            }
        }
        $batch->update(['status' => 'committed', 'committed_by' => $admin->id, 'committed_at' => now(),
            'valid_count' => $imported, 'error_count' => $batch->rows()->where('status', 'error')->count()]);
        $this->audit->record('import.committed', 'import_batch', $batch->id, null,
            ['imported' => ['old' => null, 'new' => $imported], 'failed_at_commit' => ['old' => null, 'new' => $failed]], $batch->source_name, $admin);
        Cache::forget('dashboard:counts');

        return $batch->refresh();
    }

    /** Section 28.4 — undo a committed batch within the window, if nobody has worked on its records since. */
    public function rollback(ImportBatch $batch, User $admin, string $reason): ImportBatch
    {
        if ($admin->role !== Role::Admin) {
            throw DomainRuleViolation::on('batch', 'Only an Admin can roll back an import.');
        }
        if ($batch->status !== 'committed') {
            throw DomainRuleViolation::on('batch', 'Only committed imports can be rolled back.');
        }
        if ($batch->committed_at->lt(now()->subDays(config('splms.import.rollback_days')))) {
            throw DomainRuleViolation::on('batch', 'Imports can be rolled back for '.config('splms.import.rollback_days').' days only.');
        }
        $rows = $batch->rows()->where('status', 'imported')->get();
        $studentIds = $rows->pluck('created_entity_ids.student_id')->filter()->values();
        $credentialIds = $rows->pluck('created_entity_ids.credential_id')->filter()->values();
        $affectedStudents = $rows->map(fn ($r) => $r->created_entity_ids['student_id'] ?? Credential::find($r->created_entity_ids['credential_id'] ?? 0)?->student_id)->filter()->unique();

        // Blocked once people have worked on the records.
        $touched = AuditLog::whereIn('student_id', $affectedStudents)->where('occurred_at', '>', $batch->committed_at)
            ->whereNotNull('actor_user_id')->whereNotIn('action', ['student.created', 'credential.created', 'import.committed'])->exists()
            || DB::table('renewal_cases')->whereIn('credential_id', $credentialIds)->exists()
            || Student::whereIn('id', $studentIds)->whereNotNull('user_id')->exists();
        if ($touched) {
            throw DomainRuleViolation::on('batch', 'Records from this import have been edited, renewed or invited since, so it can no longer be rolled back. Correct them individually.');
        }

        DB::transaction(function () use ($batch, $admin, $reason, $studentIds, $credentialIds) {
            DB::table('compliance_snapshots')->whereIn('credential_id', $credentialIds)->delete();
            $periodIds = DB::table('credential_periods')->whereIn('credential_id', $credentialIds)->pluck('id');
            DB::table('notifications')->whereIn('period_id', $periodIds)->orWhereIn('student_id', $studentIds)->delete();
            DB::table('status_overrides')->whereIn('credential_id', $credentialIds)->delete();
            DB::table('credentials')->whereIn('id', $credentialIds)->update(['current_period_id' => null]);
            DB::table('credential_periods')->whereIn('credential_id', $credentialIds)->update(['superseded_by' => null]);
            DB::table('credential_periods')->whereIn('credential_id', $credentialIds)->delete();
            DB::table('credentials')->whereIn('id', $credentialIds)->delete();
            DB::table('compliance_snapshots')->whereIn('student_id', $studentIds)->delete();
            DB::table('students')->whereIn('id', $studentIds)->delete();
            $batch->rows()->where('status', 'imported')->update(['status' => 'valid', 'created_entity_ids' => null]);
            $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now(), 'rolled_back_by' => $admin->id]);
            $this->audit->record('import.rolled_back', 'import_batch', $batch->id, null,
                ['students_removed' => ['old' => null, 'new' => $studentIds->count()], 'credentials_removed' => ['old' => null, 'new' => $credentialIds->count()]],
                $reason, $admin);
        });
        // Students who existed before the import may have lost a credential: recompute them.
        app(ComplianceEngine::class)->runNightly();

        return $batch->refresh();
    }

    // ---------------------------------------------------------------- parsing helpers

    /** @return array{0: list<string>, 1: list<array<int,string>>} */
    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);       // strip BOM
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($content, "\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $headers = array_map(fn ($h) => trim((string) $h), fgetcsv($fh, 0, $delimiter, '"', '\\') ?: []);
        $records = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;   // blank line
            }
            $records[] = $row;
        }
        fclose($fh);

        return [$headers, $records];
    }

    /** @return array<string,int> canonical field => column index */
    public function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $norm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9#.\- ]/', ' ', mb_strtolower($h))));
            $norm = rtrim($norm, '.');
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($norm, array_map(fn ($a) => rtrim($a, '.'), $aliases), true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    public function resolveType(string $value): ?CredentialType
    {
        $v = trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
        if ($v === '') {
            return null;
        }
        $types = CredentialType::where('active', true)->get();
        foreach ($types as $t) {
            if ($v === mb_strtolower($t->code) || $v === mb_strtolower($t->name)) {
                return $t;
            }
        }
        foreach (self::TYPE_VARIANTS as $code => $variants) {
            if (in_array($v, $variants, true)) {
                return $types->firstWhere('code', $code);
            }
        }

        return null;
    }

    /** @return array{0: ?CarbonImmutable, 1: bool} date, and whether it could be read both ways */
    public function parseDate(string $raw, string $convention): array
    {
        $s = trim($raw);
        $tz = Clock::timezone();
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return [checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? CarbonImmutable::create($m[1], $m[2], $m[3], 0, 0, 0, $tz) : null, false];
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            $order = $convention === 'mdy' ? [$a, $b] : [$b, $a];   // [month, day]; ISO sheets with slashes default to day-first
            [$month, $day] = $order;
            if (! checkdate($month, $day, $y)) {
                return [null, false];
            }

            return [CarbonImmutable::create($y, $month, $day, 0, 0, 0, $tz), $a <= 12 && $b <= 12 && $a !== $b];
        }
        if (preg_match('/[a-z]/i', $s)) {   // "15 Mar 2027", "March 15, 2027"
            try {
                return [CarbonImmutable::parse($s, $tz)->startOfDay(), false];
            } catch (Throwable) {
                return [null, false];
            }
        }
        if (preg_match('/^\d{5}$/', $s) && (int) $s > 20000 && (int) $s < 80000) {   // spreadsheet serial number
            return [CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $tz)->addDays((int) $s), false];
        }

        return [null, false];
    }

    /** @return array{0: string, 1: ?string, 2: string} */
    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if (str_contains($full, ',')) {             // "Dela Cruz, Juan Miguel"
            [$last, $rest] = array_map('trim', explode(',', $full, 2));
            $parts = explode(' ', $rest);

            return [array_shift($parts) ?? '', $parts ? implode(' ', $parts) : null, $last];
        }
        $parts = explode(' ', $full);
        if (count($parts) === 1) {
            return [$parts[0], null, ''];
        }
        // Filipino compound surnames: "Juan Dela Cruz", "Ana De los Santos".
        $particles = ['de', 'dela', 'del', 'delos', 'los', 'las', 'la', 'san', 'santa', 'sta.', 'sto.', 'van', 'von', 'di', 'da', 'du', 'le', 'mac', 'mc'];
        $first = array_shift($parts);
        $lastStart = count($parts) - 1;
        while ($lastStart > 0 && in_array(mb_strtolower($parts[$lastStart - 1]), $particles, true)) {
            $lastStart--;
        }
        $last = implode(' ', array_slice($parts, $lastStart));
        $middle = implode(' ', array_slice($parts, 0, $lastStart)) ?: null;

        return [$first, $middle, $last];
    }
}
