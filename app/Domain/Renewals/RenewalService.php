<?php

namespace App\Domain\Renewals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Domain\Documents\DocumentInspector;
use App\Domain\DomainRuleViolation;
use App\Domain\Notifications\NotificationService;
use App\Domain\Records\CredentialService;
use App\Enums\PeriodSource;
use App\Enums\RenewalCaseStatus as S;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Jobs\ScanDocument;
use App\Models\Credential;
use App\Models\CredentialTypeRequirement;
use App\Models\Document;
use App\Models\RejectionReason;
use App\Models\RenewalCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SRS 13.2 & 14 — the renewal workflow (UC-11 to UC-14, FR-030 to FR-041, BR-020 to BR-027).
 * Every transition locks the case row, writes a case event and an audit entry, and notifies.
 */
class RenewalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly CredentialService $credentials,
        private readonly DocumentInspector $inspector,
    ) {}

    /** FR-030 / BR-020 / BR-021. */
    public function open(Credential $credential, User $actor, ?string $reason = null): RenewalCase
    {
        $credential->loadMissing('student', 'type');
        $student = $credential->student;
        $isStaff = $actor->hasRole(Role::Admin, Role::Staff);

        if (! $student->status->isMonitored()) {
            throw DomainRuleViolation::on('credential', 'Renewals can only be opened for active students.');
        }
        if ($credential->archived_at) {
            throw DomainRuleViolation::on('credential', 'This credential is archived.');
        }
        if (! $isStaff && $student->user_id !== $actor->id) {
            throw DomainRuleViolation::on('credential', 'You can only renew your own credentials.');
        }
        if ($credential->openRenewalCase()->exists()) {
            throw DomainRuleViolation::on('credential', 'BR-020 / EC-13: A renewal for this credential is already open. Continue that one instead.');
        }
        $window = [ValidityStatus::ExpiringSoon, ValidityStatus::Expired, ValidityStatus::IncompleteData];
        if (! in_array($credential->current_status, $window, true)) {
            if (! $isStaff) {
                $opens = $credential->currentPeriod?->expiry_date?->subDays($credential->type->expiring_soon_days);
                throw DomainRuleViolation::on('credential', 'BR-021: Renewal opens when the credential is within its expiry window'
                    .($opens ? ', from '.$opens->format('d M Y') : '').'. Contact the records office if you renewed early.');
            }
            if (trim((string) $reason) === '') {
                throw DomainRuleViolation::on('reason', 'BR-021: This credential is not yet due. Give a reason to open a renewal early.');
            }
        }
        if ($credential->type->requirements()->count() === 0) {
            throw DomainRuleViolation::on('credential', 'No document checklist is configured for '.$credential->type->name.'. An Admin must set one up first.');
        }

        return DB::transaction(function () use ($credential, $actor, $reason) {
            $case = new RenewalCase(['credential_id' => $credential->id, 'opened_by' => $actor->id, 'status' => S::Draft]);
            $case->last_activity_at = now();
            $case->save();
            $this->event($case, null, S::Draft, $actor, $reason);
            $this->audit->record('renewal.opened', 'renewal_case', $case->id, $credential->student_id,
                ['status' => ['old' => null, 'new' => S::Draft->value]], $reason, $actor);

            return $case;
        });
    }

    /** FR-032 to FR-034, FR-038 — upload one file against one checklist item. */
    public function upload(RenewalCase $case, CredentialTypeRequirement $requirement, UploadedFile $file, User $actor): Document
    {
        $case->loadMissing('credential.student', 'credential.type');
        $this->assertCanAct($case, $actor);

        if (! in_array($case->status, [S::Draft, S::NeedsCorrection], true)) {
            throw DomainRuleViolation::on('file', 'Files can be added only while the renewal is a draft or needs correction. It is now '.$case->status->label().'.');
        }
        if ($requirement->credential_type_id !== $case->credential->credential_type_id) {
            throw DomainRuleViolation::on('requirement_id', 'This document is not on the checklist for '.$case->credential->type->name.'.');
        }
        $current = $case->currentDocuments()->where('requirement_id', $requirement->id)->first();
        if ($case->status === S::NeedsCorrection && $current && $current->verification_status === 'approved') {
            throw DomainRuleViolation::on('file', 'EC-08: This document was already approved. Only rejected documents need replacing.');
        }

        $requirement->loadMissing('documentType');
        $info = $this->inspector->inspect($file, $requirement->documentType);

        // BR-023: total size per case.
        $total = (int) $case->currentDocuments()->where('id', '<>', $current?->id ?? 0)->sum('size_bytes') + $info['size'];
        $maxCase = config('splms.documents.max_case_mb');
        if ($total > $maxCase * 1048576) {
            throw DomainRuleViolation::on('file', "BR-023: All files in one renewal together may not exceed {$maxCase} MB.");
        }

        $key = $case->credential->student_id.'/'.$case->id.'/'.Str::uuid().'.'.$info['extension'];
        Storage::disk('documents')->putFileAs(dirname($key), $file, basename($key));

        try {
            $doc = DB::transaction(function () use ($case, $requirement, $info, $key, $actor, $current) {
                $case = RenewalCase::lockForUpdate()->find($case->id);
                $doc = Document::create([
                    'case_id' => $case->id,
                    'student_id' => $case->credential->student_id,
                    'requirement_id' => $requirement->id,
                    'document_type_id' => $requirement->document_type_id,
                    'version_no' => ($current?->version_no ?? 0) + 1,
                    'original_filename' => $info['display_name'],
                    'storage_key' => $key,
                    'mime_type' => $info['mime'],
                    'size_bytes' => $info['size'],
                    'sha256' => $info['sha256'],
                    'scan_status' => 'pending',
                    'verification_status' => 'pending',
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);
                $current?->update(['verification_status' => 'superseded']);
                $case->forceFill(['last_activity_at' => now(), 'draft_warning_sent_at' => null])->save();

                $this->audit->record('document.uploaded', 'document', $doc->id, $doc->student_id, [
                    'document_type' => ['old' => null, 'new' => $requirement->documentType->name],
                    'version' => ['old' => $current?->version_no, 'new' => $doc->version_no],
                    'sha256' => ['old' => null, 'new' => $info['sha256']],
                ], $actor->role === Role::Student ? null : 'Uploaded by staff on the student\'s behalf', $actor);

                return $doc;
            });
        } catch (\Throwable $e) {
            Storage::disk('documents')->delete($key);
            throw $e;
        }

        ScanDocument::dispatch($doc->id)->afterCommit();

        return $doc->refresh();
    }

    /** UC-11 step 5 / BR-022 — submit or resubmit. */
    public function submit(RenewalCase $case, User $actor): RenewalCase
    {
        $this->assertCanAct($case, $actor);

        return DB::transaction(function () use ($case, $actor) {
            $case = RenewalCase::lockForUpdate()->with('credential.student', 'credential.type.requirements.documentType')->find($case->id);
            if (! in_array($case->status, [S::Draft, S::NeedsCorrection], true)) {
                throw DomainRuleViolation::on('case', 'This renewal has already been submitted.');
            }
            $problems = $this->checklistProblems($case);
            if ($problems) {
                throw new DomainRuleViolation(['checklist' => 'BR-022: Before submitting: '.implode('; ', $problems).'.']);
            }
            $to = $case->status === S::NeedsCorrection ? S::Resubmitted : S::Submitted;
            $from = $case->status;
            $case->forceFill(['status' => $to, 'submitted_at' => now(), 'last_activity_at' => now(),
                'assigned_reviewer_id' => null, 'locked_at' => null])->save();
            $event = $this->event($case, $from, $to, $actor);
            $this->audit->record('renewal.submitted', 'renewal_case', $case->id, $case->credential->student_id,
                ['status' => ['old' => $from->value, 'new' => $to->value]], null, $actor);

            $this->notifications->event('renewal_submitted', $case->credential->student,
                $this->notifications->vars($case->credential) + ['case_status' => $to->label()],
                ['student', 'staff'], "case:{$case->id}:event:{$event->id}", $case);

            return $case;
        });
    }

    /** UC-12 step 2 — the case locks to one reviewer so two people don't review it at once. */
    public function claim(RenewalCase $case, User $reviewer): RenewalCase
    {
        $this->assertStaff($reviewer);

        return DB::transaction(function () use ($case, $reviewer) {
            $case = RenewalCase::lockForUpdate()->with('credential')->find($case->id);
            if ($case->status === S::UnderVerification) {
                if ($case->assigned_reviewer_id === $reviewer->id) {
                    return $case;
                }
                $who = $case->reviewer?->name ?? 'another reviewer';
                throw DomainRuleViolation::on('case', "{$who} is reviewing this renewal (since ".$case->locked_at?->timezone(Clock::timezone())->format('d M Y H:i').'). An Admin can release it.');
            }
            if (! in_array($case->status, [S::Submitted, S::Resubmitted], true)) {
                throw DomainRuleViolation::on('case', 'Only submitted renewals can be reviewed. This one is '.$case->status->label().'.');
            }
            $from = $case->status;
            $case->forceFill(['status' => S::UnderVerification, 'assigned_reviewer_id' => $reviewer->id,
                'locked_at' => now(), 'last_activity_at' => now()])->save();
            $this->event($case, $from, S::UnderVerification, $reviewer);
            $this->audit->record('renewal.claimed', 'renewal_case', $case->id, $case->credential->student_id,
                ['status' => ['old' => $from->value, 'new' => S::UnderVerification->value]], null, $reviewer);

            return $case;
        });
    }

    /** EC-17 — Admin releases a stuck lock; the case returns to the queue. */
    public function release(RenewalCase $case, User $admin, string $reason): RenewalCase
    {
        if ($admin->role !== Role::Admin) {
            throw DomainRuleViolation::on('case', 'Only an Admin can release a review lock.');
        }

        return DB::transaction(function () use ($case, $admin, $reason) {
            $case = RenewalCase::lockForUpdate()->with('credential')->find($case->id);
            if ($case->status !== S::UnderVerification) {
                return $case;
            }
            $hasRejected = $case->currentDocuments()->where('verification_status', 'rejected')->exists();
            $to = $hasRejected ? S::Resubmitted : S::Submitted;
            $case->forceFill(['status' => $to, 'assigned_reviewer_id' => null, 'locked_at' => null])->save();
            $this->event($case, S::UnderVerification, $to, $admin, $reason);
            $this->audit->record('renewal.released', 'renewal_case', $case->id, $case->credential->student_id,
                ['status' => ['old' => S::UnderVerification->value, 'new' => $to->value]], $reason, $admin);

            return $case;
        });
    }

    /** FR-036 / FR-037 / BR-024 / BR-025 / BR-031 — one decision on one document. */
    public function review(Document $document, string $decision, ?string $reasonCode, ?string $note, User $reviewer): RenewalCase
    {
        $this->assertStaff($reviewer);
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw DomainRuleViolation::on('decision', 'Choose approve or reject.');
        }

        return DB::transaction(function () use ($document, $decision, $reasonCode, $note, $reviewer) {
            $case = RenewalCase::lockForUpdate()->with('credential.student', 'credential.type')->find($document->case_id);
            $document = Document::lockForUpdate()->find($document->id);

            if ($case->status !== S::UnderVerification || $case->assigned_reviewer_id !== $reviewer->id) {
                throw DomainRuleViolation::on('case', 'Start the review first (the case must be under verification and assigned to you).');
            }
            if ($document->verification_status === 'superseded') {
                throw DomainRuleViolation::on('document', 'This is an older version; review the current one.');
            }
            if ($document->uploaded_by === $reviewer->id) {
                throw DomainRuleViolation::on('document', 'BR-031: You uploaded this document, so another staff member must verify it.');
            }
            if ($document->scan_status !== 'clean') {
                throw DomainRuleViolation::on('document', 'This file has not passed the virus scan yet.');
            }

            $old = $document->verification_status;
            if ($decision === 'approve') {
                $document->update(['verification_status' => 'approved', 'rejection_reason_code' => null,
                    'rejection_note' => null, 'verified_by' => $reviewer->id, 'verified_at' => now()]);
            } else {
                $reason = $reasonCode ? RejectionReason::where('code', $reasonCode)->where('active', true)->first() : null;
                if (! $reason) {
                    throw DomainRuleViolation::on('reason_code', 'BR-024: Choose a rejection reason.');
                }
                if ($reason->requires_note && trim((string) $note) === '') {
                    throw DomainRuleViolation::on('note', 'BR-024: Explain the reason, so the student knows what to fix.');
                }
                $document->update(['verification_status' => 'rejected', 'rejection_reason_code' => $reason->code,
                    'rejection_note' => $note ? trim($note) : null, 'verified_by' => $reviewer->id, 'verified_at' => now()]);
            }
            $this->audit->record('document.'.($decision === 'approve' ? 'approved' : 'rejected'), 'document', $document->id,
                $document->student_id, ['verification_status' => ['old' => $old, 'new' => $document->verification_status]],
                $decision === 'reject' ? trim(($document->rejectionReason?->label ?? '').'. '.($note ?? ''), '. ') : null, $reviewer);

            $case->forceFill(['last_activity_at' => now()])->save();

            return $this->settleCase($case, $reviewer);
        });
    }

    /** Once every current document has a decision, move the case on. */
    private function settleCase(RenewalCase $case, User $reviewer): RenewalCase
    {
        $docs = $case->currentDocuments()->with('documentType', 'rejectionReason')->get();
        if ($docs->contains(fn ($d) => $d->verification_status === 'pending')) {
            return $case;
        }
        $rejected = $docs->where('verification_status', 'rejected');
        $student = $case->credential->student;

        if ($rejected->isNotEmpty()) {
            $case->forceFill(['status' => S::NeedsCorrection, 'assigned_reviewer_id' => null, 'locked_at' => null])->save();
            $event = $this->event($case, S::UnderVerification, S::NeedsCorrection, $reviewer, $rejected->count().' document(s) rejected');
            $this->audit->record('renewal.needs_correction', 'renewal_case', $case->id, $student->id,
                ['status' => ['old' => S::UnderVerification->value, 'new' => S::NeedsCorrection->value]], null, $reviewer);
            $reasons = $rejected->map(fn ($d) => "- {$d->documentType->name}: {$d->rejectionReason?->label}".($d->rejection_note ? " — {$d->rejection_note}" : ''))->implode("\n");
            $this->notifications->event('case_needs_correction', $student,
                $this->notifications->vars($case->credential) + ['reasons' => $reasons, 'case_status' => S::NeedsCorrection->label()],
                ['student'], "case:{$case->id}:event:{$event->id}", $case);

            return $case;
        }

        $missing = $this->checklistProblems($case, requireApproved: true);
        if ($missing) {
            return $case;   // e.g. a mandatory item has no approved document yet
        }

        $case->forceFill(['status' => S::Approved, 'approved_at' => now()])->save();
        $event = $this->event($case, S::UnderVerification, S::Approved, $reviewer);
        $this->audit->record('renewal.approved', 'renewal_case', $case->id, $student->id,
            ['status' => ['old' => S::UnderVerification->value, 'new' => S::Approved->value]], null, $reviewer);
        $this->notifications->event('case_approved', $student,
            $this->notifications->vars($case->credential) + ['case_status' => S::Approved->label()],
            ['student'], "case:{$case->id}:event:{$event->id}", $case);

        return $case;
    }

    /** UC-14 / FR-039 / BR-026 — record the new period from the verified document and close the case. */
    public function complete(RenewalCase $case, ?string $licenseNumber, ?string $issueDate, ?string $expiryDate, User $reviewer, bool $confirmed = false): RenewalCase
    {
        $this->assertStaff($reviewer);

        return DB::transaction(function () use ($case, $licenseNumber, $issueDate, $expiryDate, $reviewer, $confirmed) {
            $case = RenewalCase::lockForUpdate()->with('credential.student', 'credential.type')->find($case->id);
            if ($case->status !== S::Approved) {
                throw DomainRuleViolation::on('case', 'All documents must be approved before the renewal can be completed.');
            }
            if (! $expiryDate && $case->credential->type->expires) {
                throw DomainRuleViolation::on('expiry_date', 'Enter the new expiry date from the approved document.');
            }
            $this->credentials->addPeriod($case->credential, $licenseNumber, $issueDate, $expiryDate, $reviewer,
                PeriodSource::Renewal, $case->id, "Renewal case #{$case->id}", $confirmed);

            $case->forceFill(['status' => S::Completed, 'completed_at' => now(), 'assigned_reviewer_id' => null, 'locked_at' => null])->save();
            $event = $this->event($case, S::Approved, S::Completed, $reviewer);
            $this->audit->record('renewal.completed', 'renewal_case', $case->id, $case->credential->student_id,
                ['status' => ['old' => S::Approved->value, 'new' => S::Completed->value]], null, $reviewer);

            $credential = $case->credential->fresh(['type', 'currentPeriod', 'student']);
            $this->notifications->event('case_completed', $credential->student,
                $this->notifications->vars($credential) + ['case_status' => S::Completed->label()],
                ['student'], "case:{$case->id}:event:{$event->id}", $case);

            return $case;
        });
    }

    /** FR-041 — students cancel before review starts; staff any time with a reason. */
    public function cancel(RenewalCase $case, ?User $actor, string $reason): RenewalCase
    {
        return DB::transaction(function () use ($case, $actor, $reason) {
            $case = RenewalCase::lockForUpdate()->with('credential.student')->find($case->id);
            if (! $case->isOpen()) {
                return $case;
            }
            if ($actor && $actor->role === Role::Student) {
                $this->assertCanAct($case, $actor);
                if (! in_array($case->status, [S::Draft, S::Submitted], true)) {
                    throw DomainRuleViolation::on('case', 'FR-041: Review has started, so only the records office can cancel this renewal now.');
                }
            } elseif ($actor && trim($reason) === '') {
                throw DomainRuleViolation::on('reason', 'Give a reason for cancelling.');
            }
            $from = $case->status;
            $case->forceFill(['status' => S::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason ?: null,
                'assigned_reviewer_id' => null, 'locked_at' => null])->save();
            $this->event($case, $from, S::Cancelled, $actor, $reason);
            $this->audit->record('renewal.cancelled', 'renewal_case', $case->id, $case->credential->student_id,
                ['status' => ['old' => $from->value, 'new' => S::Cancelled->value]], $reason, $actor);

            return $case;
        });
    }

    /** BR-027 — warn drafts idle 45 days, cancel at 60. Run daily. */
    public function housekeeping(): array
    {
        $cfg = config('splms.renewals');
        $today = Clock::today();
        $warned = 0;
        $cancelled = 0;

        RenewalCase::where('status', S::Draft->value)->where('last_activity_at', '<=', $today->subDays($cfg['draft_cancel_days'])->endOfDay())
            ->get()->each(function ($case) use (&$cancelled, $cfg) {
                $this->cancel($case, null, "Draft unused for {$cfg['draft_cancel_days']} days (BR-027)");
                $cancelled++;
            });

        RenewalCase::where('status', S::Draft->value)->whereNull('draft_warning_sent_at')
            ->where('last_activity_at', '<=', $today->subDays($cfg['draft_warning_days'])->endOfDay())
            ->with('credential.student', 'credential.type', 'credential.currentPeriod')->get()
            ->each(function (RenewalCase $case) use (&$warned, $cfg) {
                $this->notifications->event('draft_cancel_warning', $case->credential->student,
                    $this->notifications->vars($case->credential) + ['action_line' => 'Your renewal draft will be cancelled in '
                        .($cfg['draft_cancel_days'] - $cfg['draft_warning_days']).' days unless you add a document or submit it.'],
                    ['student'], "case:{$case->id}:draft-warning", $case);
                $case->forceFill(['draft_warning_sent_at' => now()])->save();
                $warned++;
            });

        return ['warned' => $warned, 'cancelled' => $cancelled];
    }

    /** @return list<string> what still blocks submission (or approval) */
    public function checklistProblems(RenewalCase $case, bool $requireApproved = false): array
    {
        $case->loadMissing('credential.type.requirements.documentType');
        $docs = $case->currentDocuments()->get()->keyBy('requirement_id');
        $problems = [];
        foreach ($case->credential->type->requirements as $req) {
            $doc = $docs->get($req->id);
            $name = $req->documentType->name;
            if (! $doc) {
                if ($req->mandatory) {
                    $problems[] = "{$name} is missing";
                }

                continue;
            }
            if ($doc->scan_status === 'pending') {
                $problems[] = "{$name} is still being scanned";
            } elseif ($doc->scan_status !== 'clean') {
                $problems[] = "{$name} failed the virus scan; upload a new copy";
            } elseif ($doc->verification_status === 'rejected') {
                $problems[] = "{$name} was rejected; upload a corrected copy";
            } elseif ($requireApproved && $req->mandatory && $doc->verification_status !== 'approved') {
                $problems[] = "{$name} is not approved";
            }
        }

        return $problems;
    }

    private function assertCanAct(RenewalCase $case, User $actor): void
    {
        $case->loadMissing('credential.student');
        if ($actor->hasRole(Role::Admin, Role::Staff)) {
            return;
        }
        if ($actor->role !== Role::Student || $case->credential->student->user_id !== $actor->id) {
            throw DomainRuleViolation::on('case', 'You can only act on your own renewals.');
        }
    }

    private function assertStaff(User $user): void
    {
        if (! $user->hasRole(Role::Admin, Role::Staff)) {
            throw DomainRuleViolation::on('case', 'Only records staff can do this.');
        }
    }

    private function event(RenewalCase $case, ?S $from, S $to, ?User $actor, ?string $remarks = null)
    {
        return $case->events()->create(['from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'remarks' => $remarks]);
    }
}
