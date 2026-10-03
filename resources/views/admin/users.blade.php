<x-layouts.app title="Users">
    <div class="page-head"><div><h1>Users</h1><p class="muted">Staff, Admin and Viewer accounts. Students get accounts by invitation from their record.</p></div></div>

    <div class="grid-2">
        <section class="panel" aria-labelledby="h-invite">
            <h2 id="h-invite">Invite a user</h2>
            <form method="post" action="{{ route('admin.users.store') }}" class="stacked">
                @csrf
                <x-field name="name" label="Full name" required />
                <x-field name="email" label="Email" type="email" required />
                <x-field name="role" label="Role" type="select" required>
                    <option value="staff">Staff: records and verification</option>
                    <option value="admin">Admin: everything, including settings</option>
                    <option value="viewer">Viewer: read-only dashboards and reports</option>
                </x-field>
                <button class="btn">Send invitation</button>
            </form>
        </section>
        <section class="panel" aria-labelledby="h-rules">
            <h2 id="h-rules">Rules</h2>
            <ul>
                <li>Admin and Staff must set up two-step verification at first sign-in.</li>
                <li>At least {{ config('splms.min_active_admins') }} active Admins must remain (BR-043).</li>
                <li>You can't change your own account here; ask another Admin.</li>
                <li>Every change is recorded in the audit log with your reason.</li>
            </ul>
        </section>
    </div>

    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Two-step</th><th scope="col">Last sign-in</th><th scope="col">Change</th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td><strong>{{ $u->name }}</strong><br><span class="muted">{{ $u->email }}</span></td>
                <td>{{ $u->role->label() }}</td>
                <td>{{ $u->isLocked() ? 'Locked' : ucfirst($u->status->value) }}</td>
                <td>{{ $u->mfa_enabled ? 'On' : 'Not set up' }}</td>
                <td class="num">{{ $u->last_login_at?->timezone(config('splms.timezone'))->format('d M Y') ?? '—' }}</td>
                <td>
                    @if ($u->id !== auth()->id())
                    <details><summary>Change</summary>
                        <form method="post" action="{{ route('admin.users.update', $u) }}" class="stacked">
                            @csrf
                            <div class="field">
                                <label for="act-{{ $u->id }}">Action</label>
                                <select id="act-{{ $u->id }}" name="action">
                                    <option value="role">Change role to…</option>
                                    @if ($u->status->value === 'deactivated')<option value="reactivate">Reactivate</option>@else<option value="deactivate">Deactivate</option>@endif
                                    @if ($u->isLocked())<option value="unlock">Unlock now</option>@endif
                                    @if ($u->mfa_enabled)<option value="reset_mfa">Reset two-step verification (lost phone)</option>@endif
                                </select>
                            </div>
                            <div class="field">
                                <label for="role-{{ $u->id }}">New role</label>
                                <select id="role-{{ $u->id }}" name="role">
                                    @foreach (['admin' => 'Admin', 'staff' => 'Staff', 'viewer' => 'Viewer'] as $v => $l)<option value="{{ $v }}" @selected($u->role->value === $v)>{{ $l }}</option>@endforeach
                                </select>
                            </div>
                            <div class="field"><label for="why-{{ $u->id }}">Reason</label><input id="why-{{ $u->id }}" type="text" name="reason" maxlength="500"></div>
                            <button class="btn secondary small">Apply</button>
                        </form>
                    </details>
                    @else <span class="muted">You</span> @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</x-layouts.app>
