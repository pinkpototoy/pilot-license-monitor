<x-layouts.app title="Import">
    <div class="page-head"><div><h1>Import students and licenses</h1><p class="muted">Move records from Google Sheets or a CSV file. Nothing is saved until you review the check results and an Admin commits them.</p></div></div>
    <div class="grid-2">
        <section class="panel" aria-labelledby="h-up">
            <h2 id="h-up">Check a file</h2>
            <form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="stacked">
                @csrf
                <div class="field">
                    <label for="file">CSV file</label>
                    <span class="hint">In Google Sheets: File → Download → Comma-separated values (.csv). One row per license; repeat the student's details on each of their rows.</span>
                    <input id="file" type="file" name="file" accept=".csv,text/csv" required>
                    @error('file')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-field name="date_convention" label="How are dates written in the sheet?" type="select" required hint="Day/month mix-ups are the most common spreadsheet error; anything that could be read both ways is flagged.">
                    <option value="dmy">Day first: 15/03/2027</option>
                    <option value="mdy">Month first: 03/15/2027</option>
                    <option value="iso">Year first: 2027-03-15</option>
                </x-field>
                <div class="actions"><button class="btn">Check file</button><a href="{{ route('imports.template') }}">Download the template</a></div>
            </form>
        </section>
        <section class="panel" aria-labelledby="h-how">
            <h2 id="h-how">How it works</h2>
            <ol>
                <li>Back up the spreadsheet and make it view-only.</li>
                <li>Check the file here. Each row is marked valid, warning or error, with reasons.</li>
                <li>Fix errors in the sheet and check again, or accept that those rows are skipped.</li>
                <li>An Admin commits the valid and warning rows. Statuses are calculated from the dates; the sheet's own status column is only compared, never copied.</li>
                <li>For {{ config('splms.import.rollback_days') }} days, an Admin can roll the whole import back if nobody has worked on the records since.</li>
            </ol>
        </section>
    </div>

    <h2>Previous imports</h2>
    @if ($batches->isEmpty())
        <p class="empty">No imports yet.</p>
    @else
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">File</th><th scope="col">Checked</th><th scope="col">Rows</th><th scope="col">Errors</th><th scope="col">Status</th></tr></thead>
            <tbody>@foreach ($batches as $b)
                <tr><td><a href="{{ route('imports.show', $b) }}">{{ $b->source_name }}</a><br><span class="muted">by {{ $b->uploader->name }}</span></td>
                    <td class="num">{{ $b->created_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}</td>
                    <td class="num">{{ $b->row_count }}</td><td class="num">{{ $b->error_count }}</td>
                    <td>{{ ['ready' => 'Checked, not committed', 'committed' => 'Committed', 'rolled_back' => 'Rolled back', 'validating' => 'Checking', 'failed' => 'Failed'][$b->status] }}</td></tr>
            @endforeach</tbody>
        </table></div>
    @endif
</x-layouts.app>
