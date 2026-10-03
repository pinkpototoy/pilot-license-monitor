<x-layouts.app title="Inbox">
    <div class="page-head">
        <h1>Inbox</h1>
        <div class="actions">
            <form method="post" action="{{ route('inbox.read-all') }}">@csrf<button class="btn secondary small">Mark all as read</button></form>
            <a class="btn secondary small" href="{{ route('preferences') }}">Notification settings</a>
        </div>
    </div>
    @if ($items->isEmpty())
        <p class="empty">No notifications yet. Reminders and updates about renewals will appear here.</p>
    @else
        <ul class="inbox">
            @foreach ($items as $n)
                <li class="{{ $n->read_at ? '' : 'unread' }}">
                    <span class="subject">@unless($n->read_at)<span class="visually-hidden">Unread: </span>@endunless{{ $n->subject }}</span>
                    <span class="when">{{ $n->created_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}</span>
                    <div class="body">{{ $n->body_rendered }}</div>
                    <div class="actions">
                        @if ($n->case_id)<a href="{{ route('renewals.show', $n->case_id) }}">Open renewal</a>@endif
                        @if ($n->student_id && auth()->user()->role->isStaffSide())<a href="{{ route('students.show', $n->student_id) }}">Open student</a>@endif
                        @unless ($n->read_at)
                            <form method="post" action="{{ route('inbox.read', $n) }}">@csrf<button class="btn link">Mark as read</button></form>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="pager">
            @if ($items->previousPageUrl())<a class="btn secondary small" href="{{ $items->previousPageUrl() }}">Newer</a>@endif
            @if ($items->nextPageUrl())<a class="btn secondary small" href="{{ $items->nextPageUrl() }}">Older</a>@endif
        </div>
    @endif
</x-layouts.app>
