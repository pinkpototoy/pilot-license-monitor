<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** FR-064 / UC-16 — Admin-only audit search. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('view-audit-log');
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor' => ['nullable', 'integer'],
            'student' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::with('actor')
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($f['student'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', Carbon::parse($v)->addDay()))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('audit.index', ['logs' => $logs, 'filters' => $f]);
    }
}
