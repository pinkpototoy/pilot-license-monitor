<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\UserAdminService;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    public function index(Request $request)
    {
        $this->authorize('manage-users');
        $role = $request->query('role');

        return view('admin.users', [
            'users' => User::when($role, fn ($q) => $q->where('role', $role), fn ($q) => $q->where('role', '<>', 'student'))
                ->orderBy('name')->paginate(40)->withQueryString(),
            'role' => $role,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'staff', 'viewer'])],
        ]);
        $this->users->invite($data['name'], $data['email'], Role::from($data['role']), $request->user());

        return back()->with('status', "Invitation sent to {$data['email']}.");
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('manage-users');
        $data = $request->validate([
            'action' => ['required', Rule::in(['role', 'deactivate', 'reactivate', 'unlock', 'reset_mfa'])],
            'role' => ['nullable', Rule::in(['admin', 'staff', 'viewer'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $admin = $request->user();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (in_array($data['action'], ['role', 'deactivate', 'reset_mfa'], true) && $reason === '') {
            return back()->withErrors(['reason' => 'Give a reason; it is recorded in the audit log.']);
        }

        match ($data['action']) {
            'role' => $this->users->changeRole($user, Role::from($data['role'] ?? $user->role->value), $admin, $reason),
            'deactivate' => $this->users->deactivate($user, $admin, $reason),
            'reactivate' => $this->users->reactivate($user, $admin, $reason ?: 'Reactivated'),
            'unlock' => $this->users->unlock($user, $admin),
            'reset_mfa' => $this->users->resetMfa($user, $admin, $reason),
        };

        return back()->with('status', "Updated {$user->name}.");
    }
}
