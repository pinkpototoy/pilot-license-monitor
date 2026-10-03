<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SRS 9 permission matrix; AC-03. Every staff route × role. */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_ac03_student_gets_404_on_every_staff_route(): void
    {
        $other = $this->student();
        $c = $this->credential($other, 'SPL', 'SPL-1', '2025-01-01', '2027-01-01');
        $me = $this->user(Role::Student);

        foreach (['/dashboard', '/students', "/students/{$other->id}", "/students/{$other->id}/edit",
            "/credentials/{$c->id}/correct", '/audit-log'] as $url) {
            $this->signedIn($me)->get($url)->assertNotFound();
        }
        $this->signedIn($me)->post("/students/{$other->id}/deactivate", ['reason' => 'x'])->assertNotFound();
    }

    public function test_student_sees_only_own_record(): void
    {
        $mine = $this->student(['first_name' => 'Owner']);
        $user = $this->user(Role::Student);
        $mine->forceFill(['user_id' => $user->id])->save();
        $this->student(['first_name' => 'Someoneelse']);

        $this->signedIn($user)->get('/my')->assertOk()->assertSee('Owner')->assertDontSee('Someoneelse');
    }

    public function test_viewer_reads_but_cannot_change(): void
    {
        $s = $this->student();
        $viewer = $this->user(Role::Viewer);
        $this->signedIn($viewer)->get('/students')->assertOk();
        $this->signedIn($viewer)->get("/students/{$s->id}")->assertOk();
        $this->signedIn($viewer)->get('/students/new')->assertForbidden();
        $this->signedIn($viewer)->put("/students/{$s->id}", ['student_number' => 'Z', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'z@example.test'])->assertForbidden();
        $this->signedIn($viewer)->get('/audit-log')->assertForbidden();
    }

    public function test_staff_cannot_override_or_view_audit_log(): void
    {
        $c = $this->credential($this->student(), 'SPL', 'SPL-2', '2025-01-01', '2027-01-01');
        $staff = $this->user(Role::Staff);
        $this->signedIn($staff)->post("/credentials/{$c->id}/override", ['override_status' => 'active', 'reason' => 'x'])->assertForbidden();
        $this->signedIn($staff)->get('/audit-log')->assertForbidden();
        $this->signedIn($this->user(Role::Admin))->get('/audit-log')->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/', '/dashboard', '/students', '/my'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
