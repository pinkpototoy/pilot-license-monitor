<?php

namespace App\Http\Controllers;

use App\Domain\Clock;
use Illuminate\Http\Request;

/** UC-10 / SRS 35 — a student sees only their own record. */
class MyRecordsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if ($user->role->isStaffSide()) {
            return redirect()->route('dashboard');
        }
        $student = $user->student()->with([
            'program.requiredCredentialTypes',
            'currentCredentials' => fn ($q) => $q->with(['type.requirements.documentType', 'currentPeriod', 'openRenewalCase']),
        ])->firstOrFail();

        return view('my.records', ['student' => $student, 'today' => Clock::today()]);
    }
}
