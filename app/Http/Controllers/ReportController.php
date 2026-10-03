<?php

namespace App\Http\Controllers;

use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportService;
use App\Models\CredentialType;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Http\Request;

/** SRS 29 / UC-15. */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('reports.index', ['catalogue' => collect(ReportService::CATALOGUE)->filter(fn ($r, $code) => $this->reports->canRun($user, $code))]);
    }

    public function show(Request $request, string $code)
    {
        abort_unless($this->reports->canRun($request->user(), $code), 404);
        $filters = $this->filters($request);
        $report = $this->reports->run($code, $filters);

        if ($format = $request->query('export')) {
            abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

            return app(ReportExporter::class)->export($code, $report, $format, $request->user());
        }

        return view('reports.show', [
            'code' => $code, 'report' => $report, 'meta' => ReportService::CATALOGUE[$code],
            'preview' => $report['rows']->take(200), 'filters' => $filters,
            'types' => CredentialType::orderBy('name')->get(), 'programs' => Program::orderBy('name')->get(),
            'cohorts' => Student::whereNotNull('cohort')->distinct()->orderBy('cohort')->pluck('cohort'),
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'type' => ['nullable', 'integer'],
            'program' => ['nullable', 'integer'],
            'cohort' => ['nullable', 'string', 'max:30'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'state' => ['nullable', 'in:compliant,at_risk,non_compliant,data_issue'],
            'channel' => ['nullable', 'in:email,in_app,sms'],
            'notification_status' => ['nullable', 'in:sent,failed,suppressed,cancelled'],
        ]);
    }
}
