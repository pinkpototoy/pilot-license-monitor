<x-layouts.app title="Reports">
    <div class="page-head"><div><h1>Reports</h1><p class="muted">Every report can be filtered, then exported as CSV (for spreadsheets) or PDF (for sharing). Exports are marked confidential and recorded in the audit log.</p></div></div>
    <div class="catalogue">
        @foreach ($catalogue as $code => [$title, $desc])
            <a href="{{ route('reports.show', $code) }}"><span class="code">{{ $code }}</span><strong>{{ $title }}</strong><span class="desc">{{ $desc }}</span></a>
        @endforeach
    </div>
</x-layouts.app>
