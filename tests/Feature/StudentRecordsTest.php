<?php

namespace Tests\Feature;

use App\Domain\Auth\InvitationService;
use App\Domain\DomainRuleViolation;
use App\Domain\Records\StudentService;
use App\Enums\ComplianceState;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Student;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StudentRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_br001_student_number_unique_case_insensitive_and_never_reused(): void
    {
        $s = $this->student(['student_number' => 'ABC-1']);
        app(StudentService::class)->deactivate($s, $this->user(), 'Left school');
        $this->expectException(DomainRuleViolation::class);
        $this->student(['student_number' => 'abc-1', 'email' => 'new@example.test']);
    }

    public function test_input_is_normalized(): void
    {
        $s = $this->student(['student_number' => ' x-9 ', 'email' => ' Mixed@Example.TEST ', 'contact_number' => '0917 555 1234', 'first_name' => ' Ana  Marie ']);
        $this->assertSame('X-9', $s->student_number);
        $this->assertSame('mixed@example.test', $s->email);
        $this->assertSame('+639175551234', $s->contact_number);
        $this->assertSame('Ana Marie', $s->first_name);
    }

    public function test_fr012_possible_duplicate_warning_before_saving(): void
    {
        $this->student(['first_name' => 'Rosa', 'last_name' => 'Cruz', 'date_of_birth' => '2004-02-03']);
        $staff = $this->user();

        $payload = ['student_number' => 'NEW-1', 'first_name' => 'rosa', 'last_name' => 'CRUZ', 'date_of_birth' => '2004-02-03', 'email' => 'rosa2@example.test'];
        $this->signedIn($staff)->post('/students', $payload)->assertSessionHas('possible_duplicates');
        $this->assertSame(1, Student::count());

        $this->signedIn($staff)->post('/students', $payload + ['confirm_not_duplicate' => 1])->assertRedirect();
        $this->assertSame(2, Student::count());
    }

    public function test_update_records_field_level_changes(): void
    {
        $s = $this->student(['cohort' => 'A']);
        $this->signedIn($this->user())->put("/students/{$s->id}", [
            'student_number' => $s->student_number, 'first_name' => $s->first_name, 'last_name' => $s->last_name,
            'email' => $s->email, 'cohort' => 'B', 'program_id' => $s->program_id, 'reason' => 'Moved batch',
        ])->assertRedirect();
        $log = AuditLog::where('action', 'student.updated')->first();
        $this->assertEquals(['old' => 'A', 'new' => 'B'], $log->changes['cohort']);
        $this->assertCount(1, $log->changes);
    }

    public function test_ec12_deactivation_blocks_login_revokes_sessions_and_stops_monitoring(): void
    {
        Notification::fake();
        $s = $this->student();
        $staff = $this->user();
        $user = app(InvitationService::class)->inviteStudent($s, $staff);
        $user->forceFill(['status' => UserStatus::Active])->save();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->signedIn($staff)->post("/students/{$s->id}/deactivate", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->signedIn($staff)->post("/students/{$s->id}/deactivate", ['reason' => 'Withdrew from program'])->assertRedirect();

        $s->refresh();
        $this->assertSame(StudentStatus::Deactivated, $s->status);
        $this->assertSame(ComplianceState::NotMonitored, $s->compliance_state);
        $this->assertSame(UserStatus::Deactivated, $user->refresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_fr008_invitation_creates_invited_account_and_emails_link(): void
    {
        Notification::fake();
        $s = $this->student();
        $user = app(InvitationService::class)->inviteStudent($s, $this->user());
        $this->assertSame(Role::Student, $user->role);
        $this->assertSame(UserStatus::Invited, $user->status);
        $this->assertSame($user->id, $s->refresh()->user_id);
        Notification::assertSentTo($user, InvitationNotification::class);
    }

    public function test_student_without_program_is_data_issue(): void
    {
        $s = $this->student(['program_id' => null]);
        $this->assertSame(ComplianceState::DataIssue, $s->refresh()->compliance_state);
    }

    public function test_search_by_license_number(): void
    {
        $s = $this->student(['first_name' => 'Findme']);
        $this->credential($s, 'SPL', 'SPL-4242', '2025-01-01', '2027-01-01');
        $this->student(['first_name' => 'Hidden']);
        $this->signedIn($this->user())->get('/students?q=4242')->assertOk()->assertSee('Findme')->assertDontSee('Hidden');
    }
}
