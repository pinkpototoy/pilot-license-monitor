<?php

namespace Tests\Feature;

use App\Domain\DomainRuleViolation;
use App\Domain\Import\ImportService;
use App\Domain\Records\StudentService;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Models\Credential;
use App\Models\ImportBatch;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
        Storage::fake('imports');
    }

    /** A Google Sheets export with the messy headers, spellings and dates seen in practice. */
    private function sheet(array $rows, ?array $headers = null): UploadedFile
    {
        $headers ??= ['Student No.', 'Name', 'Email Address', 'Contact', 'Course', 'Batch', 'License Type', 'License No.', 'Date Issued', 'Valid Until', 'Status'];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);

        return UploadedFile::fake()->createWithContent('Student Licenses - Sheet1.csv', stream_get_contents($fh));
    }

    private function prepare(UploadedFile $f, string $conv = 'dmy'): ImportBatch
    {
        return app(ImportService::class)->prepare($f, $conv, $this->user(Role::Staff));
    }

    private function messages(ImportBatch $b, int $row): string
    {
        return collect($b->rows()->where('row_number', $row)->first()->messages)->pluck('text')->implode(' | ');
    }

    public function test_messy_sheet_is_understood_and_problems_are_explained(): void
    {
        $b = $this->prepare($this->sheet([
            ['2026-001', 'Dela Cruz, Juan Miguel', 'JUAN@Example.com', '0917 555 1234', 'PPL', '2026-A', 'Student Pilot Lic.', 'spl 1001', '15/03/2025', '15/03/2027', 'Active'],      // 2: valid, name split info
            ['2026-001', 'Dela Cruz, Juan Miguel', 'juan@example.com', '0917 555 1234', 'PPL', '2026-A', 'medical', 'MC-1', '03/04/2026', '03/04/2027', 'Expired'],                   // 3: ambiguous dates, status mismatch
            ['', 'No Number', 'x@example.com', '', 'PPL', '', 'SPL', 'SPL-9', '01/01/2025', '01/01/2027', ''],                                                                            // 4: missing number
            ['2026-003', 'Ana De los Santos', 'ana@example.com', '', 'XYZ', '', 'SPL', 'SPL-77', '10/05/2026', '01/05/2026', ''],                                                        // 5: expiry before issue + unknown program
            ['2026-004', 'Ben Tan', 'ben@example.com', '', 'PPL', '', 'SPL', 'SPL 1001', '01/01/2025', '01/01/2027', ''],                                                                // 6: same license number as row 2
            ['2026-005', 'Cy Lim', 'not-an-email', '', 'PPL', '', 'Glider rating', 'G-1', '01/01/2025', '01/01/2027', ''],                                                               // 7: bad email, unknown type
            ['2026-006', 'Dee Go', 'dee@example.com', '', 'PPL', '', 'SPL', 'SPL-6', '2025-01-01', '', ''],                                                                               // 8: no expiry → warning
        ]));

        $this->assertSame(7, $b->row_count);
        $rows = $b->rows->keyBy('row_number');
        $this->assertSame('valid', $rows[2]->status, 'A name-split note is informational only');
        $this->assertStringContainsString('Name split as first "Juan", last "Dela Cruz"', $this->messages($b, 2));
        $this->assertSame('SPL1001', $rows[2]->raw_data['_clean']['credential']['license_number']);
        $this->assertSame('2027-03-15', $rows[2]->raw_data['_clean']['credential']['expiry_date']);
        $this->assertStringContainsString('could be read two ways', $this->messages($b, 3));
        $this->assertStringContainsString('Sheet said "Expired"', $this->messages($b, 3));
        $this->assertSame('error', $rows[4]->status);
        $this->assertStringContainsString('Missing student number', $this->messages($b, 4));
        $this->assertStringContainsString('BR-011', $this->messages($b, 5));
        $this->assertStringContainsString('not recognised', $this->messages($b, 5));
        $this->assertSame('De los Santos', $rows[5]->raw_data['_clean']['last_name']);
        $this->assertStringContainsString('Duplicate in this file', $this->messages($b, 6));
        $this->assertStringContainsString('not a valid email', $this->messages($b, 7));
        $this->assertStringContainsString('Unknown credential type', $this->messages($b, 7));
        $this->assertSame('warning', $rows[8]->status);
        $this->assertStringContainsString('Incomplete Data', $this->messages($b, 8));
        $this->assertSame(0, Student::count(), 'Checking writes nothing to live tables');
        Storage::disk('imports')->assertExists($b->original_file_key);
    }

    public function test_ac17_commit_reconciles_and_rollback_removes(): void
    {
        $b = $this->prepare($this->sheet([
            ['A-1', 'Juan Cruz', 'a1@example.com', '09175550001', 'PPL', '', 'SPL', 'SPL-1', '15/03/2025', '15/03/2027', ''],
            ['A-1', 'Juan Cruz', 'a1@example.com', '09175550001', 'PPL', '', 'MED', 'MC-1', '15/01/2026', '15/10/2026', ''],
            ['A-2', 'Maria Reyes', 'a2@example.com', '', 'PPL', '', 'SPL', 'SPL-2', '15/03/2025', '', ''],
            ['', 'Bad Row', 'x@example.com', '', '', '', '', '', '', '', ''],
        ]));
        $admin = $this->user(Role::Admin);

        try {
            app(ImportService::class)->commit($b, $this->user(Role::Staff));
            $this->fail('Staff cannot commit');
        } catch (DomainRuleViolation) {
        }

        $b = app(ImportService::class)->commit($b, $admin);
        $counts = $b->rows()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $this->assertSame(4, $counts->sum(), 'source rows = imported + rejected');
        $this->assertSame(3, (int) $counts['imported']);
        $this->assertSame(1, (int) $counts['error']);
        $this->assertSame(2, Student::where('import_batch_id', $b->id)->count());
        $this->assertSame(3, Credential::count());
        $this->assertSame(ValidityStatus::ExpiringSoon, Credential::where('license_number', 'MC-1')->first()->current_status);
        $this->assertSame(ValidityStatus::IncompleteData, Credential::where('license_number', 'SPL-2')->first()->current_status);
        $this->assertSame('import', Credential::where('license_number', 'SPL-1')->first()->currentPeriod->source->value);

        app(ImportService::class)->rollback($b, $admin, 'Wrong date convention');
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Credential::count());
        $this->assertSame('rolled_back', $b->refresh()->status);
    }

    public function test_rollback_blocked_after_people_edit_the_records(): void
    {
        $b = $this->prepare($this->sheet([['A-9', 'Juan Cruz', 'a9@example.com', '', 'PPL', '', 'SPL', 'SPL-9', '15/03/2025', '15/03/2027', '']]));
        $admin = $this->user(Role::Admin);
        app(ImportService::class)->commit($b, $admin);
        $s = Student::first();
        $this->travel(1)->minutes();
        app(StudentService::class)->update($s, ['cohort' => 'B'], $admin, 'fix');
        $this->expectException(DomainRuleViolation::class);
        app(ImportService::class)->rollback($b->refresh(), $admin, 'x');
    }

    public function test_existing_student_gets_only_the_credential(): void
    {
        $s = $this->student(['student_number' => 'E-1', 'first_name' => 'Ella', 'last_name' => 'Cruz']);
        $b = $this->prepare($this->sheet([['e-1', 'Ella Cruz', 'other@example.com', '', 'PPL', '', 'SPL', 'SPL-55', '2025-03-15', '2027-03-15', '']]), 'iso');
        $this->assertStringContainsString('already exists', $this->messages($b, 2));
        app(ImportService::class)->commit($b, $this->user(Role::Admin));
        $this->assertSame(1, Student::count());
        $this->assertSame('SPL-55', $s->credentials()->first()->license_number);
    }

    public function test_file_without_student_number_column_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->prepare($this->sheet([['x']], ['Something else']));
    }

    public function test_date_parsing(): void
    {
        $svc = app(ImportService::class);
        $this->assertSame('2027-03-15', $svc->parseDate('15/03/2027', 'dmy')[0]->toDateString());
        $this->assertSame('2027-03-15', $svc->parseDate('03/15/2027', 'mdy')[0]->toDateString());
        $this->assertSame('2027-03-15', $svc->parseDate('15 Mar 2027', 'dmy')[0]->toDateString());
        $this->assertSame('2027-03-15', $svc->parseDate('March 15, 2027', 'dmy')[0]->toDateString());
        $this->assertSame('2027-03-15', $svc->parseDate('46461', 'dmy')[0]->toDateString(), 'Spreadsheet serial');
        $this->assertTrue($svc->parseDate('03/04/2027', 'dmy')[1], 'Ambiguous flagged');
        $this->assertFalse($svc->parseDate('13/04/2027', 'dmy')[1]);
        $this->assertNull($svc->parseDate('31/02/2027', 'dmy')[0]);
    }

    public function test_screens(): void
    {
        $staff = $this->user(Role::Staff);
        $this->signedIn($staff)->get('/imports')->assertOk();
        $this->signedIn($staff)->get('/imports/template')->assertOk();
        $res = $this->signedIn($staff)->post('/imports', ['file' => $this->sheet([['A-1', 'Juan Cruz', 'a@example.com', '', 'PPL', '', 'SPL', 'SPL-1', '15/03/2025', '15/03/2027', '']]), 'date_convention' => 'dmy']);
        $batch = ImportBatch::first();
        $res->assertRedirect(route('imports.show', $batch));
        $this->signedIn($staff)->get(route('imports.show', $batch))->assertOk()->assertSee('Ask an Admin');
        $this->signedIn($staff)->post(route('imports.commit', $batch))->assertForbidden();
        $this->signedIn($this->user(Role::Admin))->post(route('imports.commit', $batch))->assertRedirect();
        $this->assertSame(1, Student::count());
    }
}
