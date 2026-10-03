<x-layouts.app :title="'Import: '.$batch->source_name">
    <div class="page-head">
        <div><h1>{{ $batch->source_name }}</h1>
            <p class="muted">Checked {{ $batch->created_at->timezone(config('splms.timezone'))->format('d M Y H:i') }} by {{ $batch->uploader->name }} ·
                dates read as {{ ['dmy' => 'day first', 'mdy' => 'month first', 'iso' => 'year first'][$batch->date_convention] }} ·
                file fingerprint <span class="num" title="SHA-256">{{ substr($batch->file_sha256, 0, 12) }}</span></p></div>
        <div class="actions"><a class="btn secondary" href="{{ route('imports.errors', $batch) }}">Download problem rows</a></div>
    </div>

    <nav class="readout" aria-label="Row results">
        <div class="tone-ink"><span class="value">{{ $batch->row_count }}</span><span class="label">Rows in file</span></div>
        <div class="tone-green"><span class="value">{{ ($counts['valid'] ?? 0) + ($counts['imported'] ?? 0) }}</span><span class="label">{{ $batch->status === 'committed' ? 'Imported or valid' : 'Valid' }}</span></div>
        <div class="tone-yellow"><span class="value">{{ $counts['warning'] ?? 0 }}</span><span class="label">Warnings (will import)</span></div>
        <div class="tone-red"><span class="value">{{ $counts['error'] ?? 0 }}</span><span class="label">Errors (skipped)</span></div>
    </nav>

    @if ($batch->status === 'ready')
        <section class="panel" aria-labelledby="h-commit">
            <h2 id="h-commit">Commit</h2>
            @if (auth()->user()->role->value === 'admin')
                <p>This will create {{ ($counts['valid'] ?? 0) + ($counts['warning'] ?? 0) }} rows' worth of students and credentials. Rows with errors are skipped and stay listed here. Check the warnings first.</p>
                <form method="post" action="{{ route('imports.commit', $batch) }}">@csrf<button class="btn">Commit import</button></form>
            @else
                <p>Ask an Admin to review and commit this import.</p>
            @endif
        </section>
    @elseif ($batch->status === 'committed')
        <div class="notice">Committed {{ $batch->committed_at->timezone(config('splms.timezone'))->format('d M Y H:i') }} by {{ $batch->committer?->name }}.</div>
        @if (auth()->user()->role->value === 'admin' && $batch->committed_at->gt(now()->subDays(config('splms.import.rollback_days'))))
            <details class="panel"><summary>Roll back this import</summary>
                <form method="post" action="{{ route('imports.rollback', $batch) }}" class="stacked">@csrf
                    <p>Removes the students and credentials this import created. Only possible while nobody has edited, renewed or invited them.</p>
                    <x-field name="reason" label="Reason" required />
                    <button class="btn danger">Roll back</button>
                </form>
            </details>
        @endif
    @elseif ($batch->status === 'rolled_back')
        <div class="notice warn">Rolled back {{ $batch->rolled_back_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}.</div>
    @endif

    <nav class="actions" aria-label="Rows to show">
        @foreach (['problems' => 'Errors and warnings', 'errors' => 'Errors only', 'all' => 'All rows'] as $k => $l)
            <a class="btn small {{ $filter === $k ? '' : 'secondary' }}" href="{{ route('imports.show', ['batch' => $batch, 'show' => $k]) }}">{{ $l }}</a>
        @endforeach
    </nav>
    <p></p>
    @if ($rows->isEmpty())
        <p class="empty">No rows in this view.</p>
    @else
        <div class="table-wrap"><table class="compact">
            <thead><tr><th scope="col">Row</th><th scope="col">Result</th><th scope="col">Student</th><th scope="col">Credential</th><th scope="col">Messages</th></tr></thead>
            <tbody>@foreach ($rows as $r)
                @php($c = $r->raw_data['_clean'] ?? [])
                <tr>
                    <td class="num">{{ $r->row_number }}</td>
                    <td><span class="badge {{ ['valid' => 'green', 'imported' => 'green', 'warning' => 'yellow', 'error' => 'red'][$r->status] ?? 'grey' }}">{{ ucfirst($r->status) }}</span></td>
                    <td><span class="num">{{ $c['student_number'] ?? '' }}</span><br>{{ trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) }}</td>
                    <td>@if ($cr = $c['credential'] ?? null)<span class="num">{{ $cr['license_number'] ?? 'no number' }}</span><br><span class="muted">{{ $cr['expiry_date'] ?? 'no expiry' }}</span>@else<span class="muted">Student only</span>@endif</td>
                    <td><ul class="changes">@foreach ($r->messages ?? [] as $m)<li class="msg-{{ $m['level'] }}"><strong>{{ ucfirst($m['level']) }}:</strong> {{ $m['text'] }}</li>@endforeach</ul></td>
                </tr>
            @endforeach</tbody>
        </table></div>
        <div class="pager">
            @if ($rows->previousPageUrl())<a class="btn secondary small" href="{{ $rows->previousPageUrl() }}">Previous</a>@endif
            @if ($rows->nextPageUrl())<a class="btn secondary small" href="{{ $rows->nextPageUrl() }}">Next</a>@endif
        </div>
    @endif
</x-layouts.app>
