<x-layouts.app title="Notification settings">
    <div class="page-head"><div><h1>Notification settings</h1><p class="muted">Choose how you hear about each kind of update.</p></div></div>
    <form method="post" class="panel">
        @csrf
        <div class="table-wrap"><table class="prefs">
            <thead><tr><th scope="col">Update</th><th scope="col">Email</th><th scope="col">In the app</th></tr></thead>
            <tbody>
            @foreach ($cats as $code => $label)
                <tr>
                    <th scope="row">{{ $label }}</th>
                    @foreach (['email', 'in_app'] as $ch)
                        @php($locked = $ch === 'email' && in_array($code, $mandatory, true))
                        <td>
                            <input type="checkbox" name="on[{{ $code }}][{{ $ch }}]" value="1" aria-label="{{ $label }} by {{ $ch === 'email' ? 'email' : 'in-app notification' }}"
                                @checked($locked || ($prefs[$code.'|'.$ch] ?? true)) @disabled($locked)>
                            @if ($locked)<span class="muted">Always on</span>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="muted">Expiry reminders by email can't be turned off: missing one could mean flying on a lapsed license.</p>
        <button class="btn">Save settings</button>
    </form>
</x-layouts.app>
