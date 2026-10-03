<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportService;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
        // Seeded set: 2 active, 2 expiring within 30 days, 1 expired, 1 incomplete.
        $a = $this->student(['first_name' => 'Ana']);
        $this->credential($a, 'SPL', 'SPL-1', '2025-01-01', '2027-06-01');
        $this->credential($a, 'MED', 'MC-1', '2026-01-01', '2026-10-20');
        $b = $this->student(['first_name' => 'Ben']);
        $this->credential($b, 'SPL', 'SPL-2', '2025-01-01', '2027-08-01');
        $this->credential($b, 'MED', 'MC-2', '2025-01-01', '2026-09-01');
        $c = $this->student(['first_name' => 'Cai']);
        $this->credential($c, 'SPL', '=HYPERLINK("http://evil")', '2025-01-01', '2026-10-30');
        $this->credential($c, 'MED', 'MC-3', '2026-01-01', null);
    }

    public function test_every_report_runs_with_correct_totals(): void
    {
        $svc = app(ReportService::class);
        $expect = ['RPT-01' => 2, 'RPT-02' => 2, 'RPT-03' => 1, 'RPT-06' => 3];
        foreach (array_keys(ReportService::CATALOGUE) as $code) {
            $r = $svc->run($code, []);
            $this->assertNotEmpty($r['columns'], $code);
            if (isset($expect[$code])) {
                $this->assertCount($expect[$code], $r['rows'], $code);
            }
        }
        $this->assertSame(32, $svc->run('RPT-03', [])['rows']->first()['days'], 'Days overdue: 1 Sep to 3 Oct');
        $dq = $svc->run('RPT-09', [])['rows']->pluck('issue')->implode('|');
        $this->assertStringContainsString('Medical Certificate: no expiry date', $dq);
        $this->assertSame(1, $svc->run('RPT-02', ['days' => 20])['rows']->count());
    }

    public function test_ac16_csv_export_is_confidential_audited_and_injection_safe(): void
    {
        $admin = $this->user(Role::Admin);
        $res = $this->signedIn($admin)->get('/reports/RPT-02?export=csv');
        $res->assertOk();
        $this->assertStringContainsString('CONFIDENTIAL_RPT-02', $res->headers->get('Content-Disposition'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('CONFIDENTIAL - contains personal data', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Formula is neutralised');
        $this->assertSame(1, ReportExport::where('report_code', 'RPT-02')->where('row_count', 2)->count());
        $this->assertTrue(AuditLog::where('action', 'report.exported')->where('actor_user_id', $admin->id)->exists());   // BR-042
    }

    public function test_pdf_export(): void
    {
        $res = $this->signedIn($this->user(Role::Staff))->get('/reports/RPT-06?export=pdf');
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_point_in_time_compliance_from_snapshots(): void
    {
        $this->artisan('compliance:evaluate');
        $this->freezeToday('2026-10-25');
        $this->artisan('compliance:evaluate');
        $then = app(ReportService::class)->run('RPT-06', ['as_of' => '2026-10-03'])['rows']->firstWhere('student', 'Ana Student'.'');
        $rows = app(ReportService::class)->run('RPT-06', ['as_of' => '2026-10-03'])['rows'];
        $ana = $rows->first(fn ($r) => str_starts_with($r['student'], 'Ana'));
        $this->assertSame('At Risk', $ana['state']);
        $now = app(ReportService::class)->run('RPT-06', [])['rows']->first(fn ($r) => str_starts_with($r['student'], 'Ana'));
        $this->assertSame('Non-Compliant', $now['state']);
    }

    public function test_access_viewer_reads_reports_but_not_audit_extract(): void
    {
        $viewer = $this->user(Role::Viewer);
        $this->signedIn($viewer)->get('/reports')->assertOk()->assertDontSee('Audit extract');
        $this->signedIn($viewer)->get('/reports/RPT-01')->assertOk()->assertSee('SPL-1');
        $this->signedIn($viewer)->get('/reports/RPT-10')->assertNotFound();
        $this->signedIn($this->user(Role::Admin))->get('/reports/RPT-10')->assertOk();
        $this->signedIn($this->user(Role::Student))->get('/reports')->assertNotFound();
    }

    public function test_cell_escaping(): void
    {
        $this->assertSame("'=1+1", ReportExporter::cell('=1+1'));
        $this->assertSame("'@SUM(A1)", ReportExporter::cell('@SUM(A1)'));
        $this->assertSame('-5', ReportExporter::cell('-5'));
        $this->assertSame('SPL-1', ReportExporter::cell('SPL-1'));
    }
}
