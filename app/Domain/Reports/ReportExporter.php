<?php

namespace App\Domain\Reports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Models\ReportExport;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FR-062, FR-065, BR-042 — CSV/PDF exports, marked confidential and audited. */
class ReportExporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function export(string $code, array $report, string $format, User $user): Response
    {
        $rows = $report['rows'];
        $stamp = Clock::today()->format('Ymd');
        $filename = "CONFIDENTIAL_{$code}_".str_replace(' ', '-', strtolower($report['title']))."_{$stamp}.{$format}";

        ReportExport::create(['report_code' => $code, 'filters' => $report['filters'], 'format' => $format,
            'row_count' => $rows->count(), 'requested_by' => $user->id]);
        $this->audit->record('report.exported', 'report', null, null,
            ['format' => ['old' => null, 'new' => $format], 'rows' => ['old' => null, 'new' => $rows->count()]],
            "{$code} {$report['title']}; filters: ".json_encode($report['filters']), $user);

        return $format === 'pdf' ? $this->pdf($code, $report, $filename, $user) : $this->csv($code, $report, $filename, $user);
    }

    private function csv(string $code, array $report, string $filename, User $user): StreamedResponse
    {
        return response()->streamDownload(function () use ($code, $report, $user) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows names correctly
            fputcsv($out, ["CONFIDENTIAL - contains personal data. {$code} {$report['title']}. Generated ".now()->timezone(Clock::timezone())->format('Y-m-d H:i')." by {$user->name}."]);
            fputcsv($out, array_values($report['columns']));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($k) => self::cell($row[$k] ?? ''), array_keys($report['columns'])));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function pdf(string $code, array $report, string $filename, User $user): Response
    {
        $html = view('reports.pdf', [
            'code' => $code, 'report' => $report, 'user' => $user,
            'generated' => now()->timezone(Clock::timezone())->format('d M Y H:i'),
        ])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);   // never fetch external resources while rendering
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', count($report['columns']) > 6 ? 'landscape' : 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 7, [0.35, 0.4, 0.47]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** CSV/formula injection defence: a cell starting with = + - @ is shown as text in spreadsheets. */
    public static function cell(mixed $v): string
    {
        $s = (string) $v;

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($s) ? "'".$s : $s;
    }
}
