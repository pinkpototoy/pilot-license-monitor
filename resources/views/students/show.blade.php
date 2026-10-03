<x-layouts.app :title="$student->fullName()">
    <div class="page-head">
        <div>
            <h1>{{ $student->fullName() }}</h1>
            <p><span class="num">{{ $student->student_number }}</span> · <x-badge :status="$student->compliance_state" />
                @if ($student->status->value !== 'active') · <strong>{{ $student->status->label() }}</strong>@endif</p>
        </div>
        <div class="actions">
            @can('update', $student)<a class="btn secondary" href="{{ route('students.edit', $student) }}">Edit details</a>@endcan
            @can('create', \App\Models\Credential::class)
                @if ($student->status->isMonitored())<a class="btn" href="{{ route('credentials.create', $student) }}">Record credential</a>@endif
            @endcan
        </div>
    </div>

    @if ($student->compliance_state->value === 'data_issue' && ! $student->program_id)
        <div class="notice warn">No program is assigned, so the system can't tell which credentials this student must hold. <a href="{{ route('students.edit', $student) }}">Assign a program</a>.</div>
    @endif
    @if ($missingTypes->isNotEmpty())
        <div class="notice error">Required for {{ $student->program->name }} but not recorded: {{ $missingTypes->pluck('name')->join(', ') }}.</div>
    @endif

    <h2 class="visually-hidden">Credentials</h2>
    @forelse ($student->credentials as $c)
        <article class="credential tone-{{ $c->current_status->tone() }}" aria-labelledby="cred-{{ $c->id }}">
            <header>
                <h3 id="cred-{{ $c->id }}">{{ $c->type->name }}@if($c->archived_at) <span class="muted">(archived)</span>@endif</h3>
                <div class="actions">
                    <x-badge :status="$c->current_status" />
                    @if ($c->openRenewalCase)<x-badge :status="$c->openRenewalCase->status" />@endif
                </div>
            </header>
            <div class="inner">
                @php($p = $c->periods->firstWhere('superseded_at', null))
                <dl class="facts">
                    <dt>License number</dt><dd class="num">{{ $c->license_number ?? 'Not recorded' }}</dd>
                    <dt>Issued</dt><dd>{{ $p?->issue_date?->format('d M Y') ?? 'Not recorded' }}</dd>
                    <dt>Expires</dt><dd><x-relative-date :date="$p?->expiry_date" :today="$today" /></dd>
                </dl>
                @if ($c->activeOverride)
                    <p class="override-note"><strong>Status overridden to {{ $c->activeOverride->override_status->label() }}</strong>
                        by {{ $c->activeOverride->creator->name }}@if($c->activeOverride->valid_until) until {{ $c->activeOverride->valid_until->format('d M Y') }}@endif.
                        Reason: {{ $c->activeOverride->reason }}</p>
                @endif

                @if (! $c->archived_at)
                <div class="actions">
                    @if ($c->openRenewalCase)
                        <a class="btn small" href="{{ route('renewals.show', $c->openRenewalCase) }}">Open renewal #{{ $c->openRenewalCase->id }}</a>
                    @elseif (auth()->user()->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Staff) && $student->status->isMonitored())
                        @if (in_array($c->current_status->value, ['expiring_soon', 'expired', 'incomplete_data'], true))
                            <form method="post" action="{{ route('renewals.open', $c) }}">@csrf<button class="btn small">Start renewal for student</button></form>
                        @else
                            <details><summary>Start renewal early</summary>
                                <form method="post" action="{{ route('renewals.open', $c) }}" class="decide">@csrf
                                    <div class="field grow"><label for="early-{{ $c->id }}">Reason (not yet due)</label><input id="early-{{ $c->id }}" type="text" name="reason" required></div>
                                    <button class="btn secondary small">Start</button>
                                </form>
                            </details>
                        @endif
                    @endif
                    @can('update', $c)
                        <a class="btn secondary small" href="{{ route('credentials.period', $c) }}">Record renewed dates</a>
                        <a class="btn secondary small" href="{{ route('credentials.correct', $c) }}">Correct a mistake</a>
                    @endcan
                    @can('override', $c)
                        @if ($c->activeOverride)
                            <form method="post" action="{{ route('credentials.override.end', $c) }}">@csrf<button class="btn danger small">End override</button></form>
                        @endif
                    @endcan
                </div>
                @endif

                @if ($c->periods->count() > 1)
                <details>
                    <summary>Validity history ({{ $c->periods->count() }} periods)</summary>
                    <div class="table-wrap"><table>
                        <thead><tr><th scope="col">License number</th><th scope="col">Issued</th><th scope="col">Expires</th><th scope="col">Source</th><th scope="col">State</th></tr></thead>
                        <tbody>@foreach ($c->periods as $per)
                            <tr><td class="num">{{ $per->license_number ?? '—' }}</td><td>{{ $per->issue_date?->format('d M Y') ?? '—' }}</td><td>{{ $per->expiry_date?->format('d M Y') ?? '—' }}</td>
                            <td>{{ ucfirst($per->source->value) }}</td><td>{{ $per->superseded_at ? 'Superseded' : 'Current' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </details>
                @endif

                @can('override', $c)
                @if (! $c->archived_at && ! $c->activeOverride)
                <details>
                    <summary>Override computed status (Admin)</summary>
                    <form method="post" action="{{ route('credentials.override', $c) }}" class="stacked">
                        @csrf
                        <p class="muted">Use only for a documented exception. The override shows on the record, ends on its end date or when the dates change, and is audited.</p>
                        <x-field name="override_status" label="Show status as" type="select" required>
                            @foreach (\App\Enums\ValidityStatus::cases() as $v)<option value="{{ $v->value }}">{{ $v->label() }}</option>@endforeach
                        </x-field>
                        <x-field name="valid_until" label="Override ends on" type="date" />
                        <x-field name="reason" label="Reason" type="textarea" required />
                        <button class="btn danger">Apply override</button>
                    </form>
                </details>
                @endif
                @endcan
            </div>
        </article>
    @empty
        <p class="empty">No credentials recorded. @can('create', \App\Models\Credential::class)<a href="{{ route('credentials.create', $student) }}">Record the first one</a>.@endcan</p>
    @endforelse


    <div class="grid-2">
        <section class="panel" aria-labelledby="h-details">
            <h2 id="h-details">Details</h2>
            <dl class="facts">
                <dt>Email</dt><dd>{{ $student->email }}</dd>
                <dt>Contact</dt><dd>{{ $student->contact_number ?? '—' }}</dd>
                <dt>Program</dt><dd>{{ $student->program?->name ?? 'Not assigned' }}</dd>
                <dt>Cohort</dt><dd>{{ $student->cohort ?? '—' }}</dd>
                <dt>Account</dt><dd>
                    @if (! $student->user) No account yet
                    @else {{ ucfirst($student->user->status->value) }}@if($student->user->last_login_at), last signed in {{ $student->user->last_login_at->timezone(config('splms.timezone'))->format('d M Y') }}@endif
                    @endif
                </dd>
            </dl>
            <div class="actions">
                @can('invite', $student)
                    @if ($student->status->isMonitored() && (! $student->user || $student->user->status->value === 'invited'))
                        <form method="post" action="{{ route('students.invite', $student) }}">@csrf<button class="btn secondary small">{{ $student->user ? 'Resend invitation' : 'Send account invitation' }}</button></form>
                    @endif
                @endcan
            </div>
        </section>

        <section class="panel" aria-labelledby="h-history">
            <div class="panel-head">
                <h2 id="h-history">Recent history</h2>
                @can('view-audit-log')<a href="{{ route('audit.index', ['student' => $student->id]) }}">Full history</a>@endcan
            </div>
            @if ($history->isEmpty())
                <p class="empty">No recorded changes yet.</p>
            @else
                <ul class="timeline">
                    @foreach ($history as $log)
                        <li>
                            <span class="when">{{ $log->occurred_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}</span><br>
                            <strong>{{ \App\Support\AuditPresenter::actor($log) }}</strong> {{ \App\Support\AuditPresenter::action($log) }}
                            @if ($log->remarks)<span class="muted"> · {{ $log->remarks }}</span>@endif
                            @if ($ch = \App\Support\AuditPresenter::changes($log))<ul class="changes">@foreach($ch as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @can('deactivate', $student)
    <section class="panel" aria-labelledby="h-record">
        <h2 id="h-record">Record status</h2>
        @if ($student->status->value === 'deactivated')
            <p>This student is deactivated: no reminders, no sign-in. All records are kept.</p>
            <form method="post" action="{{ route('students.reactivate', $student) }}" class="stacked">
                @csrf
                <x-field name="reason" label="Reason for reactivating" type="textarea" required />
                <button class="btn secondary">Reactivate student</button>
            </form>
        @else
            <details>
                <summary>Deactivate this student</summary>
                <form method="post" action="{{ route('students.deactivate', $student) }}" class="stacked">
                    @csrf
                    <p>Deactivating stops all reminders, signs the student out and blocks sign-in. Records stay in reports and the audit trail.</p>
                    <x-field name="reason" label="Reason" type="textarea" required />
                    <button class="btn danger">Deactivate student</button>
                </form>
            </details>
        @endif
    </section>
    @endcan
</x-layouts.app>
