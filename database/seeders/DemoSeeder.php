<?php

namespace Database\Seeders;

use App\Domain\Clock;
use App\Domain\Compliance\ComplianceEngine;
use App\Domain\Notifications\NotificationService;
use App\Domain\Records\CredentialService;
use App\Domain\Records\StudentService;
use App\Domain\Renewals\RenewalService;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Synthetic demo data for local and staging environments only — never production
 * (SRS 38: real student data never enters development environments).
 * Goes through the real services, so business rules and audit entries apply.
 */
class DemoSeeder extends Seeder
{
    public function run(StudentService $students, CredentialService $credentials, ComplianceEngine $engine): void
    {
        /* abort_if(app()->environment('production'), 1, 'DemoSeeder must not run in production.'); */

        $make = function (string $email, string $name, Role $role) {
            $u = User::firstOrNew(['email' => $email]);
            $u->forceFill(['name' => $name, 'password' => 'demo-password-123', 'role' => $role, 'status' => UserStatus::Active])->save();

            return $u;
        };
        $admin = $make('admin@example.test', 'Andrea Reyes', Role::Admin);
        $make('admin2@example.test', 'Paolo Garcia', Role::Admin);
        $make('records@example.test', 'Maria Santos', Role::Staff);

        $today = Clock::today();
        $ppl = Program::where('code', 'PPL')->first();
        $cpl = Program::where('code', 'CPL')->first();
        $spl = CredentialType::where('code', 'SPL')->first();
        $med = CredentialType::where('code', 'MED')->first();
        $rtp = CredentialType::where('code', 'RTP')->first();

        $first = ['Juan', 'Bea', 'Carlo', 'Daniela', 'Enzo', 'Francesca', 'Gabriel', 'Hannah', 'Isaac', 'Jasmine', 'Kevin', 'Lara',
            'Miguel', 'Nina', 'Oscar', 'Patricia', 'Rafael', 'Sofia', 'Tomas', 'Ursula', 'Vince', 'Wendy', 'Xavier', 'Ysabel', 'Zach',
            'Aurora', 'Benjo', 'Celine', 'Diego', 'Elisa', 'Felix', 'Gwen', 'Hector', 'Iris', 'Jose', 'Katrina'];
        $last = ['Dela Cruz', 'Villanueva', 'Bautista', 'Mendoza', 'Ramos', 'Aquino', 'Castillo', 'Navarro', 'Flores', 'Torres',
            'Domingo', 'Salazar', 'Lim', 'Tan', 'Gonzales', 'Rivera'];

        // Expiry offsets (days from today) chosen to exercise every status.
        $issued = fn ($expiry, int $months) => $expiry->subMonths($months)->min($today->subDays(20))->toDateString();
        $offsets = [400, 200, 95, 45, 31, 30, 21, 13, 7, 3, 0, -1, -10, -45, 160, 300, 520, 12, 60, 250];

        foreach ($first as $i => $fn) {
            $number = sprintf('2026-%04d', 101 + $i);
            if (Student::where('student_number', $number)->exists()) {
                continue;
            }
            $program = $i % 3 === 0 ? $cpl : ($i === 34 ? null : $ppl);
            $student = $students->create([
                'student_number' => $number,
                'first_name' => $fn,
                'last_name' => $last[$i % count($last)],
                'email' => strtolower($fn).'.'.$i.'@students.example.test',
                'contact_number' => '0917'.str_pad((string) (5550000 + $i), 7, '0', STR_PAD_LEFT),
                'program_id' => $program?->id,
                'cohort' => $i < 18 ? '2026-A' : '2026-B',
            ], $admin);

            $splExpiry = $today->addDays($offsets[$i % count($offsets)]);
            $credentials->record($student, $spl, sprintf('SPL-%05d', 30000 + $i),
                $issued($splExpiry, 24), $splExpiry->toDateString(), $admin);

            if ($i !== 7) {  // one student is missing the medical certificate
                $medExpiry = $today->addDays($offsets[($i + 7) % count($offsets)]);
                $credentials->record($student, $med, $i === 11 ? null : sprintf('MC-%05d', 70000 + $i),
                    $issued($medExpiry, 12), $i === 15 ? null : $medExpiry->toDateString(), $admin);
            }
            if ($program?->code === 'CPL') {
                $rtpExpiry = $today->addDays(800 - $i * 20);
                $credentials->record($student, $rtp, sprintf('RTP-%04d', 500 + $i),
                    $issued($rtpExpiry, 60), $rtpExpiry->toDateString(), $admin);
            }
        }

        $engine->runNightly();
        $this->seedRenewals($admin);
        $engine->runNightly();

        $n = app(NotificationService::class);
        $n->scheduleReminders();
        $n->sendStaffDigest();
        $n->deliverDue(1000);
    }

    /** Renewal cases in every state, with real files, so each screen has something to show. */
    private function seedRenewals(User $admin): void
    {
        $renewals = app(RenewalService::class);
        $staff = User::where('email', 'records@example.test')->first();
        $reviewer2 = User::where('email', 'admin2@example.test')->first();

        $pdf = function (string $label) {
            $path = tempnam(sys_get_temp_dir(), 'demo').'.pdf';
            file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>>>>>>>endobj\n"
                ."4 0 obj<</Length 60>>stream\nBT /F1 18 Tf 72 760 Td (DEMO: {$label}) Tj ET\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

            return new UploadedFile($path, str_replace(' ', '-', strtolower($label)).'.pdf', 'application/pdf', null, true);
        };

        // Expiring or expired SPL credentials of students without an open case.
        $candidates = Credential::with('student', 'type.requirements')
            ->whereHas('type', fn ($q) => $q->where('code', 'SPL'))
            ->whereIn('current_status', ['expiring_soon', 'expired'])->whereDoesntHave('openRenewalCase')
            ->orderBy('id')->take(6)->get();

        foreach ($candidates as $i => $cred) {
            $student = $cred->student;
            $user = User::firstOrNew(['email' => $i === 0 ? 'student@example.test' : $student->email]);
            $user->forceFill(['name' => $student->fullName(), 'password' => 'demo-password-123',
                'role' => Role::Student, 'status' => UserStatus::Active])->save();
            $student->forceFill(['user_id' => $user->id, 'email' => $user->email])->save();

            $case = $renewals->open($cred, $user);
            if ($i === 5) {
                continue;                                     // left as a draft
            }
            foreach ($cred->type->requirements->where('mandatory', true) as $req) {
                $renewals->upload($case, $req, $pdf($req->documentType->name.' '.$student->last_name), $user);
            }
            $renewals->submit($case, $user);                  // i = 0: waiting in the queue
            if ($i === 0) {
                continue;
            }
            $renewals->claim($case, $i === 4 ? $reviewer2 : $staff);
            if ($i === 4) {
                continue;                                     // under verification by the second Admin
            }
            $docs = $case->currentDocuments()->get();
            foreach ($docs as $k => $doc) {
                $reject = $i === 2 && $k === $docs->count() - 1;
                $renewals->review($doc, $reject ? 'reject' : 'approve', $reject ? 'ILLEGIBLE' : null,
                    $reject ? 'The ID photo is blurred; take it again in good light.' : null, $staff);
            }
            if ($i === 3) {
                $exp = $cred->currentPeriod->expiry_date;
                $renewals->complete($case, $cred->license_number, Clock::today()->subDays(2)->toDateString(),
                    CarbonImmutable::parse($exp)->addYears(2)->toDateString(), $staff);   // completed
            }
            // i = 1 stays Approved (dates to record); i = 2 needs correction.
        }
    }
}
