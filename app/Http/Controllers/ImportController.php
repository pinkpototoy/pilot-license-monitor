<?php

namespace App\Http\Controllers;

use App\Domain\Import\ImportService;
use App\Domain\Reports\ReportExporter;
use App\Enums\Role;
use App\Models\ImportBatch;
use Illuminate\Http\Request;

/** UC-04 / SRS 28 — Staff prepare, Admin commits. */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasRole(Role::Admin, Role::Staff), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);

        return view('imports.index', ['batches' => ImportBatch::with('uploader', 'committer')->latest('id')->paginate(20)]);
    }

    public function template(Request $request)
    {
        $this->guard($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ImportService::TEMPLATE_COLUMNS);
            fputcsv($out, ['2026-0201', 'Juan', 'Santos', 'Dela Cruz', 'juan.delacruz@example.com', '0917 123 4567', '2004-05-17', 'PPL', '2026-A', 'SPL', 'SPL-12345', '2025-03-15', '2027-03-15', 'Active', '']);
            fputcsv($out, ['2026-0201', 'Juan', 'Santos', 'Dela Cruz', 'juan.delacruz@example.com', '0917 123 4567', '2004-05-17', 'PPL', '2026-A', 'MED', 'MC-88812', '2026-01-10', '2027-01-10', 'Active', 'Class 2']);
            fclose($out);
        }, 'student-license-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'date_convention' => ['required', 'in:iso,dmy,mdy'],
        ], ['file.required' => 'Choose the CSV file exported from the spreadsheet.']);
        $batch = $this->imports->prepare($request->file('file'), $data['date_convention'], $request->user());

        return redirect()->route('imports.show', $batch)->with('status', 'File checked. Review the results below before it is committed.');
    }

    public function show(Request $request, ImportBatch $batch)
    {
        $this->guard($request);
        $filter = $request->query('show', 'problems');
        $rows = $batch->rows()->orderBy('row_number')->when($filter === 'problems', fn ($q) => $q->whereIn('status', ['error', 'warning']))
            ->when($filter === 'errors', fn ($q) => $q->where('status', 'error'))->paginate(100)->withQueryString();
        $counts = $batch->rows()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return view('imports.show', ['batch' => $batch->load('uploader', 'committer'), 'rows' => $rows, 'counts' => $counts, 'filter' => $filter]);
    }

    public function errors(Request $request, ImportBatch $batch)
    {
        $this->guard($request);

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'status', 'messages', 'student_number', 'data']);
            foreach ($batch->rows()->whereIn('status', ['error', 'warning'])->orderBy('row_number')->cursor() as $r) {
                $raw = $r->raw_data;
                unset($raw['_clean']);
                fputcsv($out, array_map([ReportExporter::class, 'cell'], [$r->row_number, $r->status,
                    collect($r->messages)->map(fn ($m) => strtoupper($m['level']).': '.$m['text'])->implode(' | '),
                    $r->raw_data['_clean']['student_number'] ?? '', implode(' ; ', $raw)]));
            }
            fclose($out);
        }, "CONFIDENTIAL_import-{$batch->id}-problems.csv", ['Content-Type' => 'text/csv']);
    }

    public function commit(Request $request, ImportBatch $batch)
    {
        $this->guard($request);
        abort_unless($request->user()->role === Role::Admin, 403);
        $batch = $this->imports->commit($batch, $request->user());

        return redirect()->route('imports.show', $batch)->with('status', "Imported {$batch->valid_count} rows. Statuses were calculated and every record was audited.");
    }

    public function rollback(Request $request, ImportBatch $batch)
    {
        $this->guard($request);
        abort_unless($request->user()->role === Role::Admin, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->imports->rollback($batch, $request->user(), $data['reason']);

        return redirect()->route('imports.show', $batch)->with('status', 'Import rolled back. The original file and this record are kept.');
    }
}
