<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 22mm 12mm 18mm 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #17233b; }
    header { position: fixed; top: -16mm; left: 0; right: 0; font-size: 8pt; color: #586579; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #8a1a12; }
    h1 { font-size: 14pt; margin: 0 0 2mm; }
    .meta { color: #586579; margin-bottom: 4mm; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #17233b; color: #fff; text-align: left; padding: 3px 4px; font-size: 7.5pt; }
    td { padding: 3px 4px; border-bottom: 0.5px solid #cdd5de; vertical-align: top; }
    tr:nth-child(even) td { background: #f3f5f7; }
</style></head>
<body>
<header>{{ config('app.name') }} · {{ $code }} {{ $report['title'] }}</header>
<footer>CONFIDENTIAL: contains personal data. Handle under the organization's data protection policy.</footer>
<h1>{{ $report['title'] }}</h1>
<div class="meta">
    Generated {{ $generated }} (Asia/Manila) by {{ $user->name }} · {{ $report['rows']->count() }} rows
    @if ($report['filters']) · Filters: @foreach ($report['filters'] as $k => $v){{ str_replace('_', ' ', $k) }} = {{ $v }}@if(! $loop->last), @endif @endforeach @endif
</div>
<table>
    <thead><tr>@foreach ($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
    <tbody>@forelse ($report['rows'] as $row)<tr>@foreach (array_keys($report['columns']) as $k)<td>{{ $row[$k] ?? '' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}">No rows.</td></tr>@endforelse</tbody>
</table>
</body></html>
