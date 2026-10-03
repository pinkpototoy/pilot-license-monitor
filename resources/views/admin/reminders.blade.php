<x-layouts.app title="Reminders">
    <div class="page-head"><div><h1>Reminders</h1><p class="muted">When students and staff are reminded about expiring credentials. Changes apply from the next daily run at 06:00.</p></div></div>

    <div class="table-wrap"><table>
        <thead><tr><th scope="col">When</th><th scope="col">Applies to</th><th scope="col">Who</th><th scope="col">How</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
        @foreach ($rules as $r)
            <tr>
                <td><strong>{{ $r->describe() }}</strong></td>
                <td>{{ $r->credentialType?->name ?? 'All credentials' }}</td>
                <td>{{ ['student' => 'Student', 'staff' => 'Staff', 'both' => 'Student and staff'][$r->recipients] }}</td>
                <td>{{ collect($r->channels)->map(fn ($c) => ['email' => 'Email', 'in_app' => 'In-app', 'sms' => 'SMS'][$c])->implode(', ') }}</td>
                <td>{{ $r->active ? 'On' : 'Off' }}</td>
                <td>@if ($r->active)<form method="post" action="{{ route('admin.rules.update', $r) }}">@csrf<input type="hidden" name="delete" value="1"><button class="btn link">Turn off</button></form>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <p class="muted">Staff always receive reminders in the app; email for staff comes as one weekday digest. While a renewal is open, reminders pause except on the expiry day itself (BR-034).</p>

    <section class="panel" aria-labelledby="h-add">
        <h2 id="h-add">Add a reminder</h2>
        <form method="post" action="{{ route('admin.rules.store') }}" class="decide">
            @csrf
            <div class="field"><label for="days">Days</label><input id="days" type="text" name="days" inputmode="numeric" value="{{ old('days', 21) }}" required></div>
            <div class="field"><label for="when">When</label><select id="when" name="when"><option value="before">before expiry</option><option value="on">on the expiry date</option><option value="after">after expiry</option></select></div>
            <div class="field"><label for="ct">Credential</label><select id="ct" name="credential_type_id"><option value="">All credentials</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
            <div class="field"><label for="rec">Who</label><select id="rec" name="recipients"><option value="student">Student</option><option value="both">Student and staff</option><option value="staff">Staff only</option></select></div>
            <fieldset class="field"><legend>How</legend>
                <label class="check"><input type="checkbox" name="channels[]" value="email" checked> Email</label>
                <label class="check"><input type="checkbox" name="channels[]" value="in_app" checked> In-app</label>
                @if (config('splms.notifications.sms_enabled'))<label class="check"><input type="checkbox" name="channels[]" value="sms"> SMS</label>@endif
            </fieldset>
            <button class="btn">Add reminder</button>
        </form>
    </section>

    <h2>Message wording</h2>
    <p class="muted">Placeholders in double braces are filled in for each message:
        @foreach ($placeholders as $k => $d)<code title="{{ $d }}">{{ '{'.'{'.$k.'}'.'}' }}</code>@if(! $loop->last), @endif @endforeach</p>
    @foreach ($templates as $tpl)
        <details class="panel">
            <summary><strong>{{ ucfirst(str_replace('_', ' ', $tpl->code)) }}</strong> · {{ $tpl->channel === 'in_app' ? 'In-app' : 'Email' }} · version {{ $tpl->version }}</summary>
            <form method="post" action="{{ route('admin.templates.update', $tpl) }}" class="stacked">
                @csrf
                <div class="field"><label for="s-{{ $tpl->id }}">Subject</label><input id="s-{{ $tpl->id }}" type="text" name="subject" value="{{ $tpl->subject }}"></div>
                <div class="field"><label for="b-{{ $tpl->id }}">Message</label><textarea id="b-{{ $tpl->id }}" name="body" rows="6">{{ $tpl->body }}</textarea></div>
                <button class="btn secondary small">Save new version</button>
            </form>
        </details>
    @endforeach
</x-layouts.app>
