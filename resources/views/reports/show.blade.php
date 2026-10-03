@php($uses = $meta[2])
<x-layouts.app :title="$report['title']">
    <div class="page-head">
        <div><h1>{{ $report['title'] }}</h1><p class="muted"><span class="num">{{ $code }}</span> · {{ $meta[1] }}</p></div>
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">Export CSV</a>
            <a class="btn secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}">Export PDF</a>
        </div>
    </div>
    <form method="get" class="filters" aria-label="Report filters">
        @if (in_array('type', $uses))
            <div class="field"><label for="type">Credential</label><select id="type" name="type"><option value="">All</option>
                @foreach ($types as $t)<option value="{{ $t->id }}" @selected((int)($filters['type'] ?? 0) === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
        @endif
        @if (in_array('program', $uses))
            <div class="field"><label for="program">Program</label><select id="program" name="program"><option value="">All</option>
                @foreach ($programs as $p)<option value="{{ $p->id }}" @selected((int)($filters['program'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        @endif
        @if (in_array('cohort', $uses))
            <div class="field"><label for="cohort">Cohort</label><select id="cohort" name="cohort"><option value="">All</option>
                @foreach ($cohorts as $c)<option @selected(($filters['cohort'] ?? '') === $c)>{{ $c }}</option>@endforeach</select></div>
        @endif
        @if (in_array('days', $uses))
            <div class="field"><label for="days">Within (days)</label><input id="days" type="text" inputmode="numeric" name="days" value="{{ $filters['days'] ?? 30 }}"></div>
        @endif
        @if (in_array('as_of', $uses))
            <div class="field"><label for="as_of">As of date</label><input id="as_of" type="date" name="as_of" value="{{ $filters['as_of'] ?? '' }}" max="{{ now(config('splms.timezone'))->toDateString() }}"></div>
        @endif
        @if (in_array('state', $uses))
            <div class="field"><label for="state">Compliance</label><select id="state" name="state"><option value="">All</option>
                @foreach (['non_compliant' => 'Non-Compliant', 'data_issue' => 'Data Issue', 'at_risk' => 'At Risk', 'compliant' => 'Compliant'] as $v => $l)<option value="{{ $v }}" @selected(($filters['state'] ?? '') === $v)>{{ $l }}</option>@endforeach</select></div>
        @endif
        @if (in_array('from', $uses))
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
        @endif
        @if (in_array('channel', $uses))
            <div class="field"><label for="channel">Channel</label><select id="channel" name="channel"><option value="">All</option><option value="email" @selected(($filters['channel'] ?? '') === 'email')>Email</option><option value="in_app" @selected(($filters['channel'] ?? '') === 'in_app')>In-app</option></select></div>
            <div class="field"><label for="ns">Result</label><select id="ns" name="notification_status"><option value="">All</option>
                @foreach (['sent' => 'Sent', 'failed' => 'Failed', 'suppressed' => 'Suppressed', 'cancelled' => 'Cancelled'] as $v => $l)<option value="{{ $v }}" @selected(($filters['notification_status'] ?? '') === $v)>{{ $l }}</option>@endforeach</select></div>
        @endif
        <button class="btn secondary">Apply</button>
    </form>

    <p><strong>{{ $report['rows']->count() }}</strong> {{ Str::plural('row', $report['rows']->count()) }}@if($report['rows']->count() > 200); showing the first 200 here, and all of them in exports @endif.</p>
    @if ($preview->isEmpty())
        <p class="empty">No rows match these filters.</p>
    @else
        <div class="table-wrap"><table class="compact">
            <thead><tr>@foreach ($report['columns'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@foreach ($preview as $row)<tr>@foreach (array_keys($report['columns']) as $k)<td @class(['num' => is_numeric($row[$k] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($row[$k] ?? ''))])>{{ $row[$k] ?? '' }}</td>@endforeach</tr>@endforeach</tbody>
        </table></div>
    @endif
</x-layouts.app>
