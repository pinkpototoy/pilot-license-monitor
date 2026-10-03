<x-layouts.app title="Audit log">
    <div class="page-head"><div><h1>Audit log</h1><p class="muted">Every change and sign-in, newest first. Entries cannot be edited or deleted.</p></div></div>
    <form method="get" class="filters" aria-label="Filter audit log">
        <div class="field"><label for="action">Action starts with</label><input id="action" type="text" name="action" value="{{ $filters['action'] ?? '' }}" placeholder="e.g. credential."></div>
        <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
        <button class="btn secondary">Apply</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">When (Manila)</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Record</th><th scope="col">From</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="num">{{ $log->occurred_at->timezone(config('splms.timezone'))->format('d M Y H:i:s') }}</td>
                <td>{{ \App\Support\AuditPresenter::actor($log) }}</td>
                <td>{{ ucfirst(\App\Support\AuditPresenter::action($log)) }}
                    @if ($log->remarks)<br><span class="muted">{{ $log->remarks }}</span>@endif
                    @if ($ch = \App\Support\AuditPresenter::changes($log))<ul class="changes">@foreach($ch as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
                </td>
                <td>@if ($log->student_id)<a href="{{ route('students.show', $log->student_id) }}">Student #{{ $log->student_id }}</a>@else <span class="muted">{{ $log->entity_type }} #{{ $log->entity_id }}</span>@endif</td>
                <td class="num muted">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No entries match these filters.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pager">
        @if ($logs->previousPageUrl())<a class="btn secondary small" href="{{ $logs->previousPageUrl() }}">Newer</a>@endif
        @if ($logs->nextPageUrl())<a class="btn secondary small" href="{{ $logs->nextPageUrl() }}">Older</a>@endif
    </div>
</x-layouts.app>
