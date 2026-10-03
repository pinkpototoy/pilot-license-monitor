<?php

namespace App\Http\Controllers;

use App\Domain\Clock;
use App\Domain\Renewals\RenewalService;
use App\Enums\Role;
use App\Models\Credential;
use App\Models\CredentialTypeRequirement;
use App\Models\Document;
use App\Models\RejectionReason;
use App\Models\RenewalCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** UC-11 to UC-14 — one screen per case, shared by the student and the reviewer. */
class RenewalController extends Controller
{
    public function __construct(private readonly RenewalService $renewals) {}

    /** UC-12 step 1 — verification queue, oldest first. */
    public function queue(Request $request)
    {
        Gate::authorize('review', RenewalCase::class);
        $status = $request->query('status', 'waiting');
        $cases = RenewalCase::with(['credential.student', 'credential.type', 'reviewer'])
            ->when($status === 'waiting', fn ($q) => $q->whereIn('status', ['submitted', 'resubmitted', 'under_verification']))
            ->when($status === 'approved', fn ($q) => $q->where('status', 'approved'))
            ->when($status === 'correction', fn ($q) => $q->where('status', 'needs_correction'))
            ->when($status === 'draft', fn ($q) => $q->where('status', 'draft'))
            ->when($status === 'closed', fn ($q) => $q->whereIn('status', ['completed', 'cancelled'])->latest('updated_at'))
            ->orderByRaw('submitted_at IS NULL, submitted_at')
            ->paginate(30)->withQueryString();

        return view('renewals.queue', ['cases' => $cases, 'status' => $status, 'today' => Clock::today()]);
    }

    public function open(Request $request, Credential $credential)
    {
        $user = $request->user();
        // Students reach only their own credentials (404 otherwise).
        abort_unless($user->role->isStaffSide() || $credential->student->user_id === $user->id, 404);
        abort_if($user->role->value === 'viewer', 403);
        $case = $this->renewals->open($credential, $user, $request->input('reason'));

        return redirect()->route('renewals.show', $case)->with('status', 'Renewal started. Upload each document on the checklist, then submit.');
    }

    public function show(Request $request, RenewalCase $case)
    {
        Gate::authorize('view', $case);
        $case->load(['credential.student', 'credential.type.requirements.documentType', 'credential.currentPeriod',
            'documents.uploader', 'documents.verifier', 'documents.rejectionReason', 'events.actor', 'reviewer']);

        return view('renewals.show', [
            'case' => $case,
            'byRequirement' => $case->documents->groupBy('requirement_id'),
            'problems' => $this->renewals->checklistProblems($case),
            'reasons' => RejectionReason::where('active', true)->orderBy('label')->get(),
            'isReviewer' => $request->user()->hasRole(Role::Admin, Role::Staff),
            'today' => Clock::today(),
        ]);
    }

    public function upload(Request $request, RenewalCase $case)
    {
        Gate::authorize('act', $case);
        $data = $request->validate([
            'requirement_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:'.(20 * 1024)],
        ], ['file.required' => 'Choose a file to upload.']);
        $req = CredentialTypeRequirement::findOrFail($data['requirement_id']);
        $doc = $this->renewals->upload($case, $req, $request->file('file'), $request->user());

        return redirect()->route('renewals.show', $case)->with('status',
            $doc->refresh()->scan_status === 'infected' ? 'The file was blocked by the virus scan.' : "Uploaded {$doc->original_filename}.");
    }

    public function submit(Request $request, RenewalCase $case)
    {
        Gate::authorize('act', $case);
        $this->renewals->submit($case, $request->user());

        return redirect()->route('renewals.show', $case)->with('status', 'Submitted. The records office has been notified and will verify your documents.');
    }

    public function cancel(Request $request, RenewalCase $case)
    {
        Gate::authorize('act', $case);
        $reason = (string) $request->input('reason', '');
        $this->renewals->cancel($case, $request->user(), $reason);

        return redirect()->route('renewals.show', $case)->with('status', 'Renewal cancelled.');
    }

    public function claim(Request $request, RenewalCase $case)
    {
        Gate::authorize('review', $case);
        $this->renewals->claim($case, $request->user());

        return redirect()->route('renewals.show', $case)->with('status', 'You are now reviewing this renewal.');
    }

    public function release(Request $request, RenewalCase $case)
    {
        Gate::authorize('release', $case);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->renewals->release($case, $request->user(), $data['reason']);

        return redirect()->route('renewals.show', $case)->with('status', 'Review lock released; the renewal is back in the queue.');
    }

    public function review(Request $request, Document $document)
    {
        Gate::authorize('review', RenewalCase::class);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason_code' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $case = $this->renewals->review($document, $data['decision'], $data['reason_code'] ?? null, $data['note'] ?? null, $request->user());

        return redirect()->route('renewals.show', $case)->withFragment('doc-'.$document->id)
            ->with('status', $data['decision'] === 'approve' ? 'Document approved.' : 'Document rejected; the student will see your reason.');
    }

    public function complete(Request $request, RenewalCase $case)
    {
        Gate::authorize('review', $case);
        $data = $request->validate([
            'license_number' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['nullable', 'string', 'max:10'],
            'expiry_date' => ['nullable', 'string', 'max:10'],
        ]);
        $this->renewals->complete($case, $data['license_number'] ?? null, $data['issue_date'] ?? null,
            $data['expiry_date'] ?? null, $request->user(), $request->boolean('confirmed'));

        return redirect()->route('renewals.show', $case)->with('status', 'Renewal completed. The new validity period is recorded and the student has been notified.');
    }
}
