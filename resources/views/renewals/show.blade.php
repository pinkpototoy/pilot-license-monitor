@php
    use App\Enums\RenewalCaseStatus as S;
    $credential = $case->credential;
    $student = $credential->student;
    $me = auth()->user();
    $editable = in_array($case->status, [S::Draft, S::NeedsCorrection], true);
    $reviewing = $isReviewer && $case->status === S::UnderVerification && $case->assigned_reviewer_id === $me->id;
    $stageIndex = match ($case->status) {
        S::Draft => 0, S::Submitted, S::Resubmitted => 1, S::UnderVerification, S::NeedsCorrection => 2,
        S::Approved => 3, S::Completed => 4, S::Cancelled => -1,
    };
    $stages = ['Draft', 'Submitted', 'Verification', 'Approved', 'Completed'];
@endphp
<x-layouts.app :title="'Renewal: '.$credential->type->name">
    <div class="page-head">
        <div>
            <h1>{{ $credential->type->name }} renewal</h1>
            <p>
                @if ($isReviewer)<a href="{{ route('students.show', $student) }}">{{ $student->fullName() }}</a> · <span class="num">{{ $student->student_number }}</span> · @endif
                Case <span class="num">#{{ $case->id }}</span> · <x-badge :status="$case->status" /> · current credential <x-badge :status="$credential->current_status" />
            </p>
        </div>
        @if ($isReviewer)
            <div class="actions">
                @if (in_array($case->status, [S::Submitted, S::Resubmitted], true))
                    <form method="post" action="{{ route('renewals.claim', $case) }}">@csrf<button class="btn">Start review</button></form>
                @endif
                <a class="btn secondary" href="{{ route('renewals.queue') }}">Back to queue</a>
            </div>
        @endif
    </div>

    @if ($case->status === S::Cancelled)
        <div class="notice warn">This renewal was cancelled{{ $case->cancel_reason ? ': '.$case->cancel_reason : '' }}.</div>
    @else
        <ol class="stages" aria-label="Renewal progress">
            @foreach ($stages as $i => $label)
                @php
                    $cls = $i < $stageIndex ? 'done' : ($i === $stageIndex ? ($case->status === S::NeedsCorrection ? 'problem' : 'now') : '');
                @endphp
                <li class="{{ $cls }}" @if($i === $stageIndex) aria-current="step" @endif>
                    <strong>{{ $label }}</strong>
                    @if ($i === $stageIndex && $case->status === S::NeedsCorrection) Needs correction @elseif ($i < $stageIndex) Done @elseif ($i === $stageIndex) Now @endif
                </li>
            @endforeach
        </ol>
    @endif

    {{-- One clear next step, worded for whoever is looking (SRS 35). --}}
    @if (! $isReviewer)
        @switch($case->status)
            @case(S::Draft) <div class="notice">Upload each required document below, then submit. You can save and come back later.</div> @break
            @case(S::NeedsCorrection) <div class="notice error"><strong>Some documents need correcting.</strong> Read the reason under each rejected item, upload a corrected copy, then submit again.</div> @break
            @case(S::Submitted) @case(S::Resubmitted) <div class="notice">Submitted. The records office will verify your documents; you'll get an email when they do.</div> @break
            @case(S::UnderVerification) <div class="notice">The records office is reviewing your documents now.</div> @break
            @case(S::Approved) <div class="notice">All documents were approved. The records office is recording your new dates.</div> @break
            @case(S::Completed) <div class="notice">Renewal complete. Your {{ $credential->type->name }} is valid until {{ $credential->currentPeriod?->expiry_date?->format('d M Y') }}.</div> @break
        @endswitch
    @elseif ($case->status === S::UnderVerification && ! $reviewing)
        <div class="notice warn">{{ $case->reviewer?->name ?? 'Another reviewer' }} is reviewing this renewal (since {{ $case->locked_at?->timezone(config('splms.timezone'))->format('d M Y H:i') }}).</div>
    @elseif ($reviewing)
        <div class="notice">You are reviewing. Open each file, compare it with the checklist, then approve or reject it with a reason.</div>
    @endif

    <h2>Documents</h2>
    @foreach ($credential->type->requirements as $req)
        @php
            $versions = $byRequirement->get($req->id, collect());
            $doc = $versions->firstWhere('verification_status', '!=', 'superseded');
            $older = $versions->where('verification_status', 'superseded');
            $tone = match (true) { ! $doc => 'grey', $doc->verification_status === 'approved' => 'green',
                $doc->verification_status === 'rejected' || $doc->scan_status === 'infected' => 'red', default => 'orange' };
            $canUpload = $editable && ! ($isReviewer && $me->role->value === 'viewer') && ($doc?->verification_status !== 'approved' || $case->status === S::Draft);
        @endphp
        <article class="item tone-{{ $tone }}" id="req-{{ $req->id }}" aria-labelledby="req-h-{{ $req->id }}">
            <header>
                <h3 id="req-h-{{ $req->id }}">{{ $req->documentType->name }} @unless($req->mandatory)<span class="muted">(optional)</span>@endunless</h3>
                <x-doc-status :doc="$doc" :mandatory="$req->mandatory" />
            </header>
            <div class="inner">
                @if ($req->documentType->description)<p class="muted">{{ $req->documentType->description }}</p>@endif

                @if ($doc)
                    <div class="file" id="doc-{{ $doc->id }}">
                        <span class="name">{{ $doc->original_filename }}</span>
                        <span class="muted">{{ $doc->humanSize() }} · version {{ $doc->version_no }} · uploaded {{ $doc->uploaded_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}
                            @if ($isReviewer && $doc->uploadedOnBehalf()) · <strong>uploaded by staff ({{ $doc->uploader?->name }})</strong>@endif</span>
                        @if ($doc->isViewable() && $me->role->value !== 'viewer')
                            <a href="{{ route('documents.file', $doc) }}" target="_blank" rel="noopener">View<span class="visually-hidden"> {{ $req->documentType->name }} (opens in a new tab)</span></a>
                            <a href="{{ route('documents.file', ['document' => $doc, 'download' => 1]) }}">Download</a>
                        @endif
                    </div>
                    @if ($doc->verification_status === 'rejected')
                        <p class="reject-note"><strong>Rejected: {{ $doc->rejectionReason?->label }}.</strong> {{ $doc->rejection_note }}
                            @if ($isReviewer) <span class="muted">({{ $doc->verifier?->name }})</span>@endif</p>
                    @elseif ($doc->verification_status === 'approved' && $isReviewer)
                        <p class="muted">Approved by {{ $doc->verifier?->name }} on {{ $doc->verified_at?->timezone(config('splms.timezone'))->format('d M Y H:i') }}</p>
                    @endif
                    @if ($doc->scan_status === 'infected')
                        <p class="reject-note">This file was blocked by the virus scan and removed. Upload a clean copy.</p>
                    @endif

                    @if ($reviewing && $doc->verification_status === 'pending' && $doc->scan_status === 'clean')
                        @if ($doc->uploaded_by === $me->id)
                            <p class="muted">You uploaded this file, so another staff member must verify it (BR-031).</p>
                        @else
                            <form method="post" action="{{ route('documents.review', $doc) }}" class="decide">
                                @csrf
                                <div class="field">
                                    <label for="reason-{{ $doc->id }}">Reason (if rejecting)</label>
                                    <select id="reason-{{ $doc->id }}" name="reason_code">
                                        <option value="">Choose a reason</option>
                                        @foreach ($reasons as $r)<option value="{{ $r->code }}">{{ $r->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="field grow">
                                    <label for="note-{{ $doc->id }}">Note to the student</label>
                                    <input id="note-{{ $doc->id }}" type="text" name="note" maxlength="1000" placeholder="What exactly to fix">
                                </div>
                                <div class="actions">
                                    <button class="btn" name="decision" value="approve">Approve</button>
                                    <button class="btn danger" name="decision" value="reject">Reject</button>
                                </div>
                            </form>
                        @endif
                    @endif
                @endif

                @if ($canUpload && $me->role->value !== 'viewer')
                    <form method="post" action="{{ route('renewals.upload', $case) }}" enctype="multipart/form-data" class="upload">
                        @csrf
                        <input type="hidden" name="requirement_id" value="{{ $req->id }}">
                        <label class="visually-hidden" for="file-{{ $req->id }}">File for {{ $req->documentType->name }}</label>
                        <input id="file-{{ $req->id }}" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" capture="environment" required>
                        <button class="btn secondary small">{{ $doc ? 'Replace file' : 'Upload' }}</button>
                        <span class="muted">PDF, JPG or PNG, up to {{ $req->documentType->max_size_mb }} MB</span>
                    </form>
                @endif

                @if ($older->isNotEmpty())
                    <details><summary>Earlier versions ({{ $older->count() }})</summary>
                        <ul>@foreach ($older as $o)
                            <li>v{{ $o->version_no }} · {{ $o->original_filename }} · {{ $o->uploaded_at->timezone(config('splms.timezone'))->format('d M Y') }}
                                @if ($o->rejection_reason_code) · rejected: {{ $o->rejectionReason?->label }}@endif</li>
                        @endforeach</ul>
                    </details>
                @endif
            </div>
        </article>
    @endforeach

    @if ($editable && $me->role->value !== 'viewer')
        <section class="panel" aria-labelledby="h-submit">
            <h2 id="h-submit">{{ $case->status === S::NeedsCorrection ? 'Submit corrections' : 'Submit for verification' }}</h2>
            @if ($problems)
                <p>Before you can submit:</p>
                <ul>@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
            @else
                <p>Everything required is uploaded.</p>
            @endif
            <form method="post" action="{{ route('renewals.submit', $case) }}">@csrf
                <button class="btn" @disabled($problems)>{{ $case->status === S::NeedsCorrection ? 'Submit corrections' : 'Submit' }}</button>
            </form>
        </section>
    @endif

    @if ($isReviewer && $case->status === S::Approved)
        <section class="panel" aria-labelledby="h-complete">
            <h2 id="h-complete">Record the renewed dates</h2>
            <p>Copy the details from the approved renewed document. The current period (expires {{ $credential->currentPeriod?->expiry_date?->format('d M Y') ?? 'unknown' }}) is kept in the history.</p>
            <form method="post" action="{{ route('renewals.complete', $case) }}" class="stacked">
                @csrf
                <x-field name="license_number" label="License number" :value="$credential->license_number" hint="Change only if the renewed document shows a new number." />
                <div class="row">
                    <x-field name="issue_date" label="Issue date" type="date" required />
                    <x-field name="expiry_date" label="Expiry date" type="date" :required="$credential->type->expires" />
                </div>
                @if (session('needs_confirmation'))
                    <label class="check"><input type="checkbox" name="confirmed" value="1"> I checked these dates against the document; save them anyway</label>
                @endif
                <div class="actions"><button class="btn">Complete renewal</button></div>
            </form>
        </section>
    @endif

    <div class="grid-2">
        <section class="panel" aria-labelledby="h-timeline">
            <h2 id="h-timeline">History</h2>
            <ul class="timeline">
                @foreach ($case->events->reverse() as $e)
                    <li><span class="when">{{ $e->created_at->timezone(config('splms.timezone'))->format('d M Y H:i') }}</span><br>
                        <x-badge :status="$e->to_status" />
                        {{-- SRS 35: students don't see staff names (organization may choose otherwise). --}}
                        <span class="muted">by {{ ! $e->actor ? 'the system' : ($isReviewer || $e->actor_id === $me->id ? $e->actor->name : 'the records office') }}</span>
                        @if ($e->remarks)<br><span class="muted">{{ $e->remarks }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($case->isOpen() && $me->role->value !== 'viewer')
            <section class="panel" aria-labelledby="h-manage">
                <h2 id="h-manage">Manage</h2>
                @if ($me->role->value === 'admin' && $case->status === S::UnderVerification)
                    <details><summary>Release the review lock</summary>
                        <form method="post" action="{{ route('renewals.release', $case) }}" class="stacked">@csrf
                            <x-field name="reason" label="Reason" required />
                            <button class="btn secondary">Release to the queue</button>
                        </form>
                    </details>
                @endif
                @if ($isReviewer || in_array($case->status, [S::Draft, S::Submitted], true))
                    <details><summary>Cancel this renewal</summary>
                        <form method="post" action="{{ route('renewals.cancel', $case) }}" class="stacked">@csrf
                            <x-field name="reason" label="Reason" type="textarea" :required="$isReviewer" />
                            <button class="btn danger">Cancel renewal</button>
                        </form>
                    </details>
                @else
                    <p class="muted">Review has started. Contact the records office to cancel.</p>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
