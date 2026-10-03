<?php

namespace Tests;

use App\Domain\Records\CredentialService;
use App\Domain\Records\StudentService;
use App\Enums\Role;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function freezeToday(string $date): void
    {
        // 10:00 in Manila; "today" is computed in Asia/Manila (NFR-017).
        Carbon::setTestNow(Carbon::parse($date.' 10:00', 'Asia/Manila'));
    }

    protected function seedReference(): void
    {
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function user(Role $role = Role::Staff): User
    {
        return User::factory()->role($role)->create();
    }

    /** Signed in with MFA already passed, as after a full login. */
    protected function signedIn(User $user): static
    {
        return $this->actingAs($user)->withSession(['mfa_passed' => true, 'last_seen_at' => now()->getTimestamp(), 'session_started_at' => now()->getTimestamp()]);
    }

    protected function student(array $overrides = [], ?User $actor = null): Student
    {
        static $n = 0;
        $n++;

        return app(StudentService::class)->create(array_merge([
            'student_number' => 'T-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'first_name' => 'Test', 'last_name' => 'Student'.$n,
            'email' => "student{$n}@example.test",
            'program_id' => Program::where('code', 'PPL')->value('id'),
        ], $overrides), $actor ?? $this->user(Role::Admin));
    }

    protected function credential(Student $student, string $typeCode, ?string $number, ?string $issue, ?string $expiry, ?User $actor = null): Credential
    {
        return app(CredentialService::class)->record($student, CredentialType::where('code', $typeCode)->firstOrFail(),
            $number, $issue, $expiry, $actor ?? $this->user(Role::Staff));
    }

    /** A small, structurally valid PDF. */
    protected function pdf(string $name = 'license.pdf', string $text = 'Renewed license'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n% {$text}\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** A real PNG of the given size, built byte by byte (no GD needed). */
    protected function png(string $name = 'photo.png', int $w = 240, int $h = 240): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $raw = str_repeat("\0".str_repeat("\x80\x80\x80", $w), $h);
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    /** A PDF carrying the EICAR antivirus test string. */
    protected function infectedPdf(): UploadedFile
    {
        return $this->pdf('virus.pdf', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    }
}
