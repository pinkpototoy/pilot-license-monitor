<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CredentialType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Smoke tests: every screen renders with real data and shows text labels with statuses. */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
    }

    public function test_staff_screens_render(): void
    {
        $s = $this->student(['first_name' => 'Lea']);
        $c = $this->credential($s, 'SPL', 'SPL-5', '2025-01-01', '2026-10-10');
        $admin = $this->user(Role::Admin);

        $this->signedIn($admin)->get('/dashboard')->assertOk()->assertSee('Expiring Soon')->assertSee('Lea');
        $this->signedIn($admin)->get('/students')->assertOk()->assertSee('Non-Compliant');
        $this->signedIn($admin)->get("/students/{$s->id}")->assertOk()->assertSee('SPL-5')->assertSee('in 7 days');
        $this->signedIn($admin)->get('/students/new')->assertOk();
        $this->signedIn($admin)->get("/students/{$s->id}/credentials/new")->assertOk();
        $this->signedIn($admin)->get("/credentials/{$c->id}/correct")->assertOk();
        $this->signedIn($admin)->get("/credentials/{$c->id}/periods/new")->assertOk();
        $this->signedIn($admin)->get('/audit-log')->assertOk()->assertSee('Recorded a credential');
    }

    public function test_credential_form_flow_and_rule_errors_show_on_the_field(): void
    {
        $s = $this->student();
        $staff = $this->user();
        $type = CredentialType::where('code', 'SPL')->value('id');

        $this->signedIn($staff)->from("/students/{$s->id}/credentials/new")
            ->post("/students/{$s->id}/credentials", ['credential_type_id' => $type, 'license_number' => 'A1', 'issue_date' => '2026-05-01', 'expiry_date' => '2026-04-01'])
            ->assertRedirect("/students/{$s->id}/credentials/new")->assertSessionHasErrors('expiry_date');

        $this->signedIn($staff)->post("/students/{$s->id}/credentials", ['credential_type_id' => $type, 'license_number' => 'A1', 'issue_date' => '2025-05-01', 'expiry_date' => '2027-04-01'])
            ->assertRedirect("/students/{$s->id}");
        $this->assertDatabaseHas('credentials', ['license_number' => 'A1', 'current_status' => 'active']);
    }

    public function test_student_home(): void
    {
        $s = $this->student(['first_name' => 'Mara']);
        $this->credential($s, 'SPL', 'SPL-123456', '2024-01-01', '2026-09-01');
        $u = $this->user(Role::Student);
        $s->forceFill(['user_id' => $u->id])->save();

        $this->signedIn($u)->get('/')->assertRedirect(route('my.records'));
        $this->signedIn($u)->get('/my')->assertOk()->assertSee('Action needed')->assertSee('••3456', false)->assertDontSee('SPL-123456');
    }

    public function test_list_shows_missing_required_credentials(): void
    {
        $s = $this->student(['first_name' => 'Nomed']);
        $this->credential($s, 'SPL', 'SPL-77', '2025-01-01', '2027-06-01');
        $this->signedIn($this->user())->get('/students?q=Nomed')->assertOk()
            ->assertSee('Missing')->assertSee('Medical Certificate is required but not recorded');
    }

    public function test_history_shows_names_not_ids_and_skips_initial_calculation(): void
    {
        $s = $this->student();
        $this->credential($s, 'SPL', 'SPL-78', '2025-01-01', '2026-10-10');
        $this->assertSame(0, AuditLog::whereIn('action', ['credential.status_changed', 'student.compliance_changed'])->count());

        $this->signedIn($this->user(Role::Admin))->get("/students/{$s->id}")->assertOk()
            ->assertSee('Private Pilot License course')->assertDontSee('program: (empty) → 1');
    }
}
