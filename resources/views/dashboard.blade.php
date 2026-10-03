<x-layouts.app title="Dashboard">
    <div class="page-head">
        <div>
            <h1>Today, {{ $today->format('d M Y') }}</h1>
            <p class="muted">
                @if ($lastRun)
                    Statuses last recalculated {{ $lastRun->started_at->timezone(config('splms.timezone'))->format('d M Y, H:i') }}
                    @if ($lastRun->status !== 'succeeded') <x-badge :status="\App\Enums\ValidityStatus::Expired" /> <strong>The last run did not finish.</strong> @endif
                @else
                    The nightly status check has not run yet.
                @endif
            </p>
        </div>
        <div class="actions">
            @can('create', \App\Models\Student::class)<a class="btn" href="{{ route('students.create') }}">Add student</a>@endcan
        </div>
    </div>

    <nav class="readout" aria-label="Summary">
        <a class="tone-ink" href="{{ route('students.index') }}"><span class="value">{{ $counts['students'] }}</span><span class="label">Students monitored</span></a>
        <a class="tone-green" href="{{ route('students.index', ['compliance' => 'compliant']) }}"><span class="value">{{ $counts['compliant'] }}</span><span class="label">Compliant students</span></a>
        <a class="tone-green" href="{{ route('students.index', ['credential_status' => 'active']) }}"><span class="value">{{ $counts['active'] }}</span><span class="label">Active credentials</span></a>
        <a class="tone-yellow" href="{{ route('students.index', ['credential_status' => 'expiring_soon']) }}"><span class="value">{{ $counts['expiring_soon'] }}</span><span class="label">Expiring soon</span></a>
        <a class="tone-red" href="{{ route('students.index', ['credential_status' => 'expired']) }}"><span class="value">{{ $counts['expired'] }}</span><span class="label">Expired credentials</span></a>
        <a class="tone-blue" href="{{ route('renewals.queue', ['status' => 'draft']) }}"><span class="value">{{ $counts['pending_renewals'] }}</span><span class="label">Pending renewals</span></a>
        <a class="tone-orange" href="{{ route('renewals.queue') }}"><span class="value">{{ $counts['pending_verification'] }}</span><span class="label">Waiting for verification</span></a>
        <a class="tone-grey" href="{{ route('students.index', ['compliance' => 'data_issue']) }}"><span class="value">{{ $counts['data_issues'] }}</span><span class="label">Data issues</span></a>
    </nav>

    <div class="grid-2">
        <section class="panel" aria-labelledby="h-soon">
            <div class="panel-head">
                <h2 id="h-soon">Expiring in 14 days, no renewal started</h2>
                <a href="{{ route('students.index', ['credential_status' => 'expiring_soon']) }}">All expiring</a>
            </div>
            @if ($expiringNoCase->isEmpty())
                <p class="empty">Nothing expires in the next 14 days without a renewal underway.</p>
            @else
                <ul class="strips">@foreach ($expiringNoCase as $c)<x-strip :credential="$c" :today="$today" />@endforeach</ul>
            @endif
        </section>

        <section class="panel" aria-labelledby="h-expired">
            <div class="panel-head">
                <h2 id="h-expired">Expired, no renewal started</h2>
                <a href="{{ route('students.index', ['credential_status' => 'expired']) }}">All expired</a>
            </div>
            @if ($expiredNoCase->isEmpty())
                <p class="empty">No expired credentials without a renewal underway.</p>
            @else
                <ul class="strips">@foreach ($expiredNoCase as $c)<x-strip :credential="$c" :today="$today" />@endforeach</ul>
            @endif
        </section>
    </div>
</x-layouts.app>
