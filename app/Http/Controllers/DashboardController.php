<?php

namespace App\Http\Controllers;

use App\Domain\Clock;
use App\Enums\RenewalCaseStatus;
use App\Models\Credential;
use App\Models\JobRun;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** SRS 34 — "who needs attention today?" */
class DashboardController extends Controller
{
    public function __invoke()
    {
        $this->authorize('view-dashboard');
        $today = Clock::today();

        // Counts cached for at most 5 minutes (SRS 34); the nightly job clears the cache.
        $counts = Cache::remember('dashboard:counts', 300, function () {
            $monitored = fn () => Student::query()->monitored();
            $cred = fn () => Credential::query()->whereNull('archived_at')
                ->whereHas('student', fn ($q) => $q->monitored());

            return [
                'students' => $monitored()->count(),
                'compliant' => $monitored()->where('compliance_state', 'compliant')->count(),
                'active' => $cred()->where('current_status', 'active')->count(),
                'expiring_soon' => $cred()->where('current_status', 'expiring_soon')->count(),
                'expired' => $cred()->where('current_status', 'expired')->count(),
                'pending_renewals' => DB::table('renewal_cases')->whereIn('status', ['draft', 'submitted', 'resubmitted'])->count(),
                'pending_verification' => DB::table('renewal_cases')->whereIn('status', ['submitted', 'resubmitted', 'under_verification'])->count(),
                'needs_correction' => DB::table('renewal_cases')->where('status', RenewalCaseStatus::NeedsCorrection->value)->count(),
                'data_issues' => $cred()->where('current_status', 'incomplete_data')->count()
                    + $monitored()->whereNull('program_id')->count(),
            ];
        });

        $attention = fn (string $status, ?int $withinDays = null) => Credential::query()
            ->whereNull('archived_at')->where('current_status', $status)
            ->whereHas('student', fn ($q) => $q->monitored())
            ->whereDoesntHave('openRenewalCase')
            ->join('credential_periods as p', 'p.id', '=', 'credentials.current_period_id')
            ->when($withinDays, fn ($q) => $q->where('p.expiry_date', '<=', $today->addDays($withinDays)->toDateString()))
            ->orderBy('p.expiry_date')
            ->select('credentials.*')
            ->with(['student', 'type', 'currentPeriod'])
            ->limit(8)->get();

        return view('dashboard', [
            'counts' => $counts,
            'expiringNoCase' => $attention('expiring_soon', 14),
            'expiredNoCase' => $attention('expired'),
            'lastRun' => JobRun::where('job_name', 'compliance:evaluate')->latest('started_at')->first(),
            'today' => $today,
        ]);
    }
}
