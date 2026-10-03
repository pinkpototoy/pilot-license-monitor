<?php

namespace Tests\Feature;

use App\Domain\Renewals\RenewalService;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CredentialType;
use App\Models\CredentialTypeRequirement;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\NotificationTemplate;
use App\Models\OutboundNotification;
use App\Models\RenewalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The Phase 3–5 screens, driven through real HTTP requests. */
class WorkflowScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
        Storage::fake('documents');
    }

    private function studentWithAccount(): array
    {
        $s = $this->student(['first_name' => 'Iza']);
        $u = $this->user(Role::Student);
        $s->forceFill(['user_id' => $u->id])->save();
        $c = $this->credential($s, 'SPL', 'SPL-300', '2024-10-15', '2026-10-15');

        return [$s, $u, $c];
    }

    private function req($c, string $code): int
    {
        return CredentialTypeRequirement::where('credential_type_id', $c->credential_type_id)
            ->whereHas('documentType', fn ($q) => $q->where('code', $code))->value('id');
    }

    public function test_student_and_staff_complete_a_renewal_through_the_screens(): void
    {
        [$s, $u, $c] = $this->studentWithAccount();
        $this->signedIn($u)->get('/my')->assertOk()->assertSee('Start renewal');
        $this->signedIn($u)->post("/credentials/{$c->id}/renewals")->assertRedirect();
        $case = RenewalCase::first();

        $this->signedIn($u)->get("/renewals/{$case->id}")->assertOk()->assertSee('Copy of renewed license')->assertSee('Not uploaded');
        $this->signedIn($u)->post("/renewals/{$case->id}/documents", ['requirement_id' => $this->req($c, 'RENEWED_LICENSE'), 'file' => $this->pdf()])->assertRedirect();
        $this->signedIn($u)->post("/renewals/{$case->id}/documents", ['requirement_id' => $this->req($c, 'GOV_ID'), 'file' => $this->png()])->assertRedirect();
        $this->signedIn($u)->post("/renewals/{$case->id}/submit")->assertRedirect();
        $this->signedIn($u)->get('/my')->assertSee('View renewal progress');

        $staff = $this->user(Role::Staff);
        $this->signedIn($staff)->get('/verification')->assertOk()->assertSee('Iza');
        $this->signedIn($staff)->post("/renewals/{$case->id}/claim")->assertRedirect();
        $this->signedIn($staff)->get("/renewals/{$case->id}")->assertOk()->assertSee('Approve');
        foreach (Document::all() as $d) {
            $this->signedIn($staff)->post("/documents/{$d->id}/review", ['decision' => 'approve'])->assertRedirect();
        }
        $this->signedIn($staff)->get("/renewals/{$case->id}")->assertSee('Record the renewed dates');
        $this->signedIn($staff)->post("/renewals/{$case->id}/complete", ['license_number' => 'SPL-300', 'issue_date' => '2026-10-01', 'expiry_date' => '2028-10-01'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $case->refresh()->status->value);
        $this->signedIn($u)->get('/inbox')->assertOk()->assertSee('Renewal complete');
    }

    public function test_rejection_reason_is_shown_to_the_student(): void
    {
        [$s, $u, $c] = $this->studentWithAccount();
        $svc = app(RenewalService::class);
        $case = $svc->open($c, $u);
        $svc->upload($case, CredentialTypeRequirement::find($this->req($c, 'RENEWED_LICENSE')), $this->pdf(), $u);
        $svc->upload($case, CredentialTypeRequirement::find($this->req($c, 'GOV_ID')), $this->png(), $u);
        $svc->submit($case, $u);
        $staff = $this->user(Role::Staff);
        $svc->claim($case, $staff);
        $doc = Document::where('requirement_id', $this->req($c, 'GOV_ID'))->first();
        $license = Document::where('requirement_id', $this->req($c, 'RENEWED_LICENSE'))->first();
        $this->signedIn($staff)->post("/documents/{$license->id}/review", ['decision' => 'approve']);
        // The case moves on only when every document has a decision.
        $this->assertSame('under_verification', $case->refresh()->status->value);
        $this->signedIn($staff)->post("/documents/{$doc->id}/review", ['decision' => 'reject'])->assertSessionHasErrors('reason_code');
        $this->signedIn($staff)->post("/documents/{$doc->id}/review", ['decision' => 'reject', 'reason_code' => 'NAME_MISMATCH', 'note' => 'ID shows a different surname']);

        $this->signedIn($u)->get("/renewals/{$case->id}")->assertSee('Some documents need correcting')
            ->assertSee('Name mismatch')->assertSee('ID shows a different surname');
        $this->signedIn($u)->get('/my')->assertSee('Fix rejected documents');
        $this->signedIn($u)->get("/renewals/{$case->id}")->assertSee('by the records office')->assertDontSee($staff->name);
    }

    public function test_document_access_rules_and_audit(): void
    {
        [$s, $u, $c] = $this->studentWithAccount();
        $svc = app(RenewalService::class);
        $case = $svc->open($c, $u);
        $doc = $svc->upload($case, CredentialTypeRequirement::find($this->req($c, 'GOV_ID')), $this->pdf(), $u);

        $this->signedIn($u)->get("/documents/{$doc->id}/file")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->signedIn($this->user(Role::Student))->get("/documents/{$doc->id}/file")->assertNotFound();
        $this->signedIn($this->user(Role::Student))->get("/renewals/{$case->id}")->assertNotFound();
        $this->signedIn($this->user(Role::Viewer))->get("/documents/{$doc->id}/file")->assertNotFound();
        $staff = $this->user(Role::Staff);
        $this->signedIn($staff)->get("/documents/{$doc->id}/file")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'document.viewed')->where('actor_user_id', $staff->id)->exists());
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get("/documents/{$doc->id}/file")->assertRedirect(route('login'));
    }

    public function test_upload_errors_show_on_the_form(): void
    {
        [$s, $u, $c] = $this->studentWithAccount();
        $case = app(RenewalService::class)->open($c, $u);
        $exe = UploadedFile::fake()->createWithContent('x.pdf', "MZ\x90\x00".str_repeat("\x00", 300));
        $this->signedIn($u)->from("/renewals/{$case->id}")->post("/renewals/{$case->id}/documents", ['requirement_id' => $this->req($c, 'GOV_ID'), 'file' => $exe])
            ->assertRedirect("/renewals/{$case->id}")->assertSessionHasErrors('file');
    }

    public function test_admin_screens_and_br043(): void
    {
        $admin = $this->user(Role::Admin);
        $other = $this->user(Role::Admin);
        foreach (['/admin/users', '/admin/credential-types', '/admin/reminders', '/reports', '/imports', '/inbox', '/settings/notifications'] as $url) {
            $this->signedIn($admin)->get($url)->assertOk();
        }
        foreach (['/admin/users', '/admin/credential-types', '/admin/reminders'] as $url) {
            $this->signedIn($this->user(Role::Staff))->get($url)->assertForbidden();
        }
        // Exactly two Admins: neither can be removed (BR-043).
        $count = User::where('role', 'admin')->where('status', 'active')->count();
        $this->signedIn($admin)->post("/admin/users/{$other->id}", ['action' => 'deactivate', 'reason' => 'left'])
            ->assertSessionHasErrors($count <= 2 ? 'user' : []);
        $this->signedIn($admin)->post("/admin/users/{$admin->id}", ['action' => 'deactivate', 'reason' => 'x'])->assertSessionHasErrors('user');

        $this->signedIn($admin)->post('/admin/users', ['name' => 'New Staff', 'email' => 'new@example.test', 'role' => 'staff'])->assertSessionHasNoErrors();
        $this->assertSame('invited', User::where('email', 'new@example.test')->first()->status->value);
    }

    public function test_reminder_rule_and_template_editing(): void
    {
        $admin = $this->user(Role::Admin);
        $this->signedIn($admin)->post('/admin/reminders', ['days' => 21, 'when' => 'before', 'recipients' => 'student', 'channels' => ['email']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notification_rules', ['offset_days' => -21, 'active' => true]);
        $this->signedIn($admin)->post('/admin/reminders', ['days' => 30, 'when' => 'before', 'recipients' => 'student', 'channels' => ['email']])->assertSessionHasErrors('days');

        $tpl = NotificationTemplate::where('code', 'expiry_reminder')->where('channel', 'email')->first();
        $this->signedIn($admin)->post("/admin/templates/{$tpl->id}", ['subject' => 'Heads up: {{credential_name}}', 'body' => 'Hi {{student_first_name}}'])->assertSessionHasNoErrors();
        $this->assertSame('Heads up: {{credential_name}}', NotificationTemplate::current('expiry_reminder', 'email')->subject);
        $this->assertFalse($tpl->refresh()->active, 'Old version kept, inactive');
    }

    public function test_credential_type_and_checklist_configuration(): void
    {
        $admin = $this->user(Role::Admin);
        $this->signedIn($admin)->post('/admin/credential-types', ['code' => 'ELP', 'name' => 'English Language Proficiency', 'expiring_soon_days' => 60, 'expires' => 1, 'active' => 1])->assertSessionHasNoErrors();
        $elp = CredentialType::where('code', 'ELP')->first();
        $doc = DocumentType::where('code', 'GOV_ID')->first();
        $this->signedIn($admin)->post("/admin/credential-types/{$elp->id}/checklist", ['document_type_id' => $doc->id, 'mandatory' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $elp->requirements()->count());
        $this->signedIn($admin)->post('/admin/credential-types', ['code' => 'bad code', 'name' => 'x', 'expiring_soon_days' => 30])->assertSessionHasErrors('code');
    }

    public function test_preferences_and_inbox(): void
    {
        [$s, $u] = $this->studentWithAccount();
        $this->signedIn($u)->post('/settings/notifications', ['on' => ['renewal_submitted' => ['email' => 1]]])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $u->id, 'event_type' => 'renewal_submitted', 'channel' => 'in_app', 'enabled' => false]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $u->id, 'event_type' => 'expiry_reminder', 'channel' => 'email']);

        $n = OutboundNotification::create(['user_id' => $u->id, 'student_id' => $s->id, 'event_type' => 'x', 'channel' => 'in_app',
            'dedupe_key' => 'k1', 'subject' => 'Hello there', 'body_rendered' => 'Body', 'status' => 'sent', 'scheduled_for' => now()]);
        $this->signedIn($u)->get('/inbox')->assertSee('Hello there')->assertSee('Unread');
        $this->signedIn($u)->post("/inbox/{$n->id}/read");
        $this->assertNotNull($n->refresh()->read_at);
        $this->signedIn($this->user(Role::Student))->post("/inbox/{$n->id}/read")->assertNotFound();
    }
}
