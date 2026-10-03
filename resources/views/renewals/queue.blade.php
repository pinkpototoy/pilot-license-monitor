<x-layouts.app title="Verification">
    <div class="page-head"><div><h1>Verification</h1><p class="muted">Oldest submissions first. Open a case to start reviewing; it locks to you.</p></div></div>
    <nav class="actions" aria-label="Filter cases">
        @foreach (['waiting' => 'Waiting for verification', 'approved' => 'Approved, dates to record', 'correction' => 'With the student for correction', 'draft' => 'Drafts', 'closed' => 'Closed'] as $k => $l)
            <a class="btn {{ $status === $k ? '' : 'secondary' }} small" href="{{ route('renewals.queue', ['status' => $k]) }}" @if($status === $k) aria-current="true" @endif>{{ $l }}</a>
        @endforeach
    </nav>
    <p></p>
    @if ($cases->isEmpty())
        <p class="empty">Nothing here right now.</p>
    @else
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Student</th><th scope="col">Credential</th><th scope="col">Status</th><th scope="col">Submitted</th><th scope="col">Waiting</th><th scope="col">Reviewer</th></tr></thead>
            <tbody>
            @foreach ($cases as $c)
                <tr>
                    <td><a href="{{ route('renewals.show', $c) }}">{{ $c->credential->student->fullName() }}</a><br><span class="num muted">{{ $c->credential->student->student_number }}</span></td>
                    <td>{{ $c->credential->type->name }}<br><x-badge :status="$c->credential->current_status" /></td>
                    <td><x-badge :status="$c->status" /></td>
                    <td class="num">{{ $c->submitted_at?->timezone(config('splms.timezone'))->format('d M Y') ?? '—' }}</td>
                    <td class="num">@if($c->submitted_at && $c->isOpen()){{ (int) $c->submitted_at->diffInDays(now()) }} days @else — @endif</td>
                    <td>{{ $c->reviewer?->name ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="pager">
            @if ($cases->previousPageUrl())<a class="btn secondary small" href="{{ $cases->previousPageUrl() }}">Previous</a>@endif
            @if ($cases->nextPageUrl())<a class="btn secondary small" href="{{ $cases->nextPageUrl() }}">Next</a>@endif
        </div>
    @endif
</x-layouts.app>
