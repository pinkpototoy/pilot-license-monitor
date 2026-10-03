<?php

namespace Tests\Feature;

use App\Domain\DomainRuleViolation;
use App\Domain\Records\StudentService;
use App\Domain\Renewals\RenewalService;
use App\Enums\RenewalCaseStatus as S;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Models\Credential;
use App\Models\CredentialTypeRequirement;
use App\Models\Document;
use App\Models\OutboundNotification;
use App\Models\RenewalCase;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenewalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RenewalService $svc;

    private Student $student;

    private User $studentUser;

    private Credential $spl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
        Storage::fake('documents');
        $this->svc = app(RenewalService::class);

        $this->student = $this->student(['first_name' => 'Rhea']);
        $this->studentUser = $this->user(Role::Student);
        $this->student->forceFill(['user_id' => $this->studentUser->id])->save();
        $this->spl = $this->credential($this->student, 'SPL', 'SPL-100', '2024-10-20', '2026-10-20');   // Expiring Soon
    }

    private function req(string $docCode): CredentialTypeRequirement
    {
        return CredentialTypeRequirement::where('credential_type_id', $this->spl->credential_type_id)
            ->whereHas('documentType', fn ($q) => $q->where('code', $docCode))->firstOrFail();
    }

    private function expectRule(string $needle, callable $fn): DomainRuleViolation
    {
        try {
            $fn();
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return $e;
        }
        $this->fail("Expected a rule violation containing '{$needle}'");
    }

    /** Draft with both mandatory documents (renewed license + ID) uploaded. */
    private function readyCase(): RenewalCase
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $this->pdf(), $this->studentUser);
        $this->svc->upload($case, $this->req('GOV_ID'), $this->png('id.png'), $this->studentUser);

        return $case->refresh();
    }

    public function test_full_round_trip_ac12(): void
    {
        $staff = $this->user(Role::Staff);
        $case = $this->readyCase();
        $this->assertSame(S::Draft, $case->status);
        $this->assertSame(2, Document::where('scan_status', 'clean')->count(), 'Sync queue scans immediately in tests');

        $this->svc->submit($case, $this->studentUser);
        $this->svc->claim($case, $staff);
        foreach ($case->currentDocuments as $doc) {
            $this->svc->review($doc, 'approve', null, null, $staff);
        }
        $this->assertSame(S::Approved, $case->refresh()->status);

        $this->svc->complete($case, 'SPL-100B', '2026-10-01', '2028-10-01', $staff);
        $this->assertSame(S::Completed, $case->refresh()->status);

        $spl = $this->spl->refresh();
        $this->assertSame(ValidityStatus::Active, $spl->current_status);
        $this->assertSame('SPL-100B', $spl->license_number);
        $this->assertSame(2, $spl->periods()->count());
        $this->assertSame($case->id, $spl->currentPeriod->renewal_case_id);
        $this->assertSame(
            ['draft', 'submitted', 'under_verification', 'approved', 'completed'],
            $case->events()->pluck('to_status')->map->value->all()
        );
        $this->assertTrue(OutboundNotification::where('event_type', 'case_completed')->where('channel', 'email')->exists());
    }

    public function test_br021_students_open_only_within_the_window_staff_need_a_reason(): void
    {
        $med = $this->credential($this->student, 'MED', 'MC-1', '2026-01-01', '2027-06-01');   // Active
        $this->expectRule('BR-021', fn () => $this->svc->open($med, $this->studentUser));
        $this->expectRule('BR-021', fn () => $this->svc->open($med, $this->user(Role::Staff)));
        $this->assertSame(S::Draft, $this->svc->open($med, $this->user(Role::Staff), 'Renewed early at the authority')->status);
    }

    public function test_ac13_br020_only_one_open_case(): void
    {
        $this->svc->open($this->spl, $this->studentUser);
        $this->expectRule('BR-020', fn () => $this->svc->open($this->spl, $this->studentUser));
    }

    public function test_students_cannot_touch_other_students_cases(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $intruder = $this->user(Role::Student);
        $this->expectRule('own', fn () => $this->svc->upload($case, $this->req('GOV_ID'), $this->pdf(), $intruder));
        $this->expectRule('own', fn () => $this->svc->submit($case, $intruder));
    }

    public function test_ac09_cannot_submit_until_mandatory_documents_are_present(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $this->pdf(), $this->studentUser);
        $e = $this->expectRule('BR-022', fn () => $this->svc->submit($case, $this->studentUser));
        $this->assertStringContainsString('Valid government ID is missing', $e->getMessage());
        // The optional receipt is not required.
        $this->assertStringNotContainsString('receipt', strtolower($e->getMessage()));
    }

    public function test_ac10_renamed_executable_is_rejected(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $exe = UploadedFile::fake()->createWithContent('license.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 200));
        $this->expectRule('FR-033', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $exe, $this->studentUser));

        $mislabelled = $this->png('scan.pdf');   // a PNG named .pdf
        $this->expectRule('FR-033', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $mislabelled, $this->studentUser));
        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_ec18_encrypted_or_broken_pdf_and_tiny_images_are_rejected(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $enc = UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.6\n1 0 obj<</Filter/Standard>>endobj\ntrailer<</Root 1 0 R/Encrypt 5 0 R>>\n%%EOF\n");
        $this->expectRule('password-protected', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $enc, $this->studentUser));
        $cut = UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n");
        $this->expectRule('damaged', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $cut, $this->studentUser));
        $this->expectRule('too small', fn () => $this->svc->upload($case, $this->req('GOV_ID'), $this->png('id.png', 50, 50), $this->studentUser));
    }

    public function test_br023_size_limit(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('0', 11 * 1048576)."\n%%EOF\n");
        $this->expectRule('BR-023', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $big, $this->studentUser));
    }

    public function test_infected_upload_is_quarantined_and_blocks_submission(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $doc = $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $this->infectedPdf(), $this->studentUser)->refresh();
        $this->assertSame('infected', $doc->scan_status);
        $this->assertStringStartsWith('quarantine/', $doc->storage_key);
        Storage::disk('documents')->assertExists($doc->storage_key);
        $this->assertTrue(OutboundNotification::where('event_type', 'upload_infected')->exists());

        $this->svc->upload($case, $this->req('GOV_ID'), $this->pdf(), $this->studentUser);
        $this->expectRule('virus scan', fn () => $this->svc->submit($case, $this->studentUser));
    }

    public function test_ac11_ec07_ec08_rejection_correction_and_resubmission(): void
    {
        $staff = $this->user(Role::Staff);
        $case = $this->readyCase();
        $this->svc->submit($case, $this->studentUser);
        $this->svc->claim($case, $staff);
        [$license, $id] = [$case->currentDocuments()->where('requirement_id', $this->req('RENEWED_LICENSE')->id)->first(),
            $case->currentDocuments()->where('requirement_id', $this->req('GOV_ID')->id)->first()];

        $this->expectRule('BR-024', fn () => $this->svc->review($id, 'reject', null, null, $staff));
        $this->expectRule('BR-024', fn () => $this->svc->review($id, 'reject', 'OTHER', '', $staff));
        $this->svc->review($license, 'approve', null, null, $staff);
        $this->svc->review($id, 'reject', 'ILLEGIBLE', 'Photo is blurred', $staff);

        $this->assertSame(S::NeedsCorrection, $case->refresh()->status);
        $email = OutboundNotification::where('event_type', 'case_needs_correction')->where('channel', 'email')->first();
        $this->assertStringContainsString('Illegible', $email->body_rendered);
        $this->assertStringContainsString('Photo is blurred', $email->body_rendered);

        // EC-08: the approved document stays approved and can't be replaced.
        $this->expectRule('EC-08', fn () => $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $this->pdf(), $this->studentUser));
        $new = $this->svc->upload($case, $this->req('GOV_ID'), $this->png('id2.png'), $this->studentUser);
        $this->assertSame(2, $new->version_no);
        $this->assertSame('superseded', $id->refresh()->verification_status);

        $this->svc->submit($case, $this->studentUser);
        $this->assertSame(S::Resubmitted, $case->refresh()->status);
        $this->svc->claim($case, $staff);
        $this->svc->review($new, 'approve', null, null, $staff);
        $this->assertSame(S::Approved, $case->refresh()->status);
    }

    public function test_br031_staff_cannot_verify_their_own_upload(): void
    {
        $staff = $this->user(Role::Staff);
        $case = $this->svc->open($this->spl, $staff);
        $this->svc->upload($case, $this->req('RENEWED_LICENSE'), $this->pdf(), $staff);
        $this->svc->upload($case, $this->req('GOV_ID'), $this->pdf(), $this->studentUser);
        $this->svc->submit($case, $staff);
        $this->svc->claim($case, $staff);
        $own = $case->currentDocuments()->where('uploaded_by', $staff->id)->first();
        $this->expectRule('BR-031', fn () => $this->svc->review($own, 'approve', null, null, $staff));
    }

    public function test_review_lock_and_admin_release_ec17(): void
    {
        [$a, $b, $admin] = [$this->user(Role::Staff), $this->user(Role::Staff), $this->user(Role::Admin)];
        $case = $this->readyCase();
        $this->svc->submit($case, $this->studentUser);
        $this->svc->claim($case, $a);
        $this->expectRule('is reviewing', fn () => $this->svc->claim($case, $b));
        $this->expectRule('Start the review', fn () => $this->svc->review($case->currentDocuments()->first(), 'approve', null, null, $b));
        $this->expectRule('Admin', fn () => $this->svc->release($case, $b, 'x'));

        $this->svc->release($case, $admin, 'Reviewer on leave');
        $this->assertSame(S::Submitted, $case->refresh()->status);
        $this->svc->claim($case, $b);
        $this->assertSame($b->id, $case->refresh()->assigned_reviewer_id);
    }

    public function test_fr041_student_cancel_only_before_review(): void
    {
        $case = $this->readyCase();
        $this->svc->submit($case, $this->studentUser);
        $this->svc->claim($case, $this->user(Role::Staff));
        $this->expectRule('FR-041', fn () => $this->svc->cancel($case, $this->studentUser, 'changed mind'));
        $this->svc->cancel($case, $this->user(Role::Admin), 'Duplicate submission');
        $this->assertSame(S::Cancelled, $case->refresh()->status);
        // A new case can now be opened (BR-020 counts open cases only).
        $this->assertSame(S::Draft, $this->svc->open($this->spl, $this->studentUser)->status);
    }

    public function test_br027_drafts_are_warned_then_cancelled(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        $this->freezeToday('2026-11-18');   // 46 days later
        $this->assertSame(['warned' => 1, 'cancelled' => 0], $this->svc->housekeeping());
        $this->assertSame(['warned' => 0, 'cancelled' => 0], $this->svc->housekeeping());
        $this->freezeToday('2026-12-03');   // 61 days later
        $this->assertSame(1, $this->svc->housekeeping()['cancelled']);
        $this->assertSame(S::Cancelled, $case->refresh()->status);
    }

    public function test_ec12_deactivation_cancels_open_renewals(): void
    {
        $case = $this->svc->open($this->spl, $this->studentUser);
        app(StudentService::class)->deactivate($this->student, $this->user(), 'Withdrew');
        $this->assertSame(S::Cancelled, $case->refresh()->status);
    }

    public function test_complete_requires_approved_case_and_expiry(): void
    {
        $staff = $this->user(Role::Staff);
        $case = $this->readyCase();
        $this->expectRule('approved', fn () => $this->svc->complete($case, null, '2026-10-01', '2028-10-01', $staff));
    }
}
