<x-layouts.app title="Credentials & checklists">
    <div class="page-head"><div><h1>Credentials &amp; checklists</h1>
        <p class="muted">What students must hold, how long each lasts, and which documents prove a renewal. Values here must be confirmed with the aviation authority (SRS Appendix E).</p></div></div>

    @foreach ($types as $t)
        <article class="credential tone-{{ $t->active ? 'green' : 'grey' }}" aria-labelledby="t-{{ $t->id }}">
            <header><h3 id="t-{{ $t->id }}">{{ $t->name }} <span class="muted num">{{ $t->code }}</span></h3>
                <span class="badge {{ $t->active ? 'green' : 'grey' }}">{{ $t->active ? 'In use' : 'Retired' }}</span></header>
            <div class="inner grid-2">
                <form method="post" action="{{ route('admin.credential-types.update', $t) }}" class="stacked">
                    @csrf
                    <div class="row">
                        <x-field name="code" label="Code" :value="$t->code" required />
                        <x-field name="name" label="Name" :value="$t->name" required />
                    </div>
                    <x-field name="issuing_authority" label="Issuing authority" :value="$t->issuing_authority" />
                    <div class="row">
                        <x-field name="expiring_soon_days" label="Expiring Soon from (days before)" type="text" :value="$t->expiring_soon_days" required />
                        <x-field name="default_validity_months" label="Usual validity (months)" :value="$t->default_validity_months" />
                    </div>
                    <div class="row">
                        <x-field name="max_validity_months" label="Warn if longer than (months)" :value="$t->max_validity_months" hint="BR-013" />
                        <x-field name="number_pattern" label="Number format (pattern)" :value="$t->number_pattern" hint="Optional, e.g. SPL-\d{5}" />
                    </div>
                    <label class="check"><input type="hidden" name="expires" value="0"><input type="checkbox" name="expires" value="1" @checked($t->expires)> This credential expires</label>
                    <label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($t->active)> In use</label>
                    <button class="btn secondary small">Save {{ $t->code }}</button>
                </form>
                <div>
                    <h4>Renewal checklist</h4>
                    @if ($t->requirements->isEmpty())<p class="empty">No documents yet. Students can't renew until at least one is added.</p>@endif
                    <ul class="timeline">
                        @foreach ($t->requirements as $r)
                            <li>
                                <strong>{{ $r->documentType->name }}</strong> · {{ $r->mandatory ? 'Required' : 'Optional' }}
                                <form method="post" action="{{ route('admin.checklist.save', $t) }}" class="inline-form">@csrf
                                    <input type="hidden" name="document_type_id" value="{{ $r->document_type_id }}">
                                    <input type="hidden" name="mandatory" value="{{ $r->mandatory ? 0 : 1 }}">
                                    <button class="btn link">Make {{ $r->mandatory ? 'optional' : 'required' }}</button>
                                </form>
                                ·
                                <form method="post" action="{{ route('admin.checklist.save', $t) }}" class="inline-form">@csrf
                                    <input type="hidden" name="document_type_id" value="{{ $r->document_type_id }}"><input type="hidden" name="remove" value="1">
                                    <button class="btn link">Remove</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <form method="post" action="{{ route('admin.checklist.save', $t) }}" class="decide">@csrf
                        <div class="field grow"><label for="add-{{ $t->id }}">Add a document</label>
                            <select id="add-{{ $t->id }}" name="document_type_id">
                                @foreach ($documentTypes->whereNotIn('id', $t->requirements->pluck('document_type_id')) as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                            </select></div>
                        <label class="check"><input type="checkbox" name="mandatory" value="1" checked> Required</label>
                        <button class="btn secondary small">Add</button>
                    </form>
                </div>
            </div>
        </article>
    @endforeach

    <div class="grid-2">
        <section class="panel" aria-labelledby="h-new">
            <h2 id="h-new">Add a credential type</h2>
            <form method="post" action="{{ route('admin.credential-types.store') }}" class="stacked">
                @csrf
                <div class="row"><x-field name="code" label="Code" required hint="Capitals, e.g. ELP" /><x-field name="name" label="Name" required /></div>
                <x-field name="expiring_soon_days" label="Expiring Soon from (days before)" value="30" required />
                <input type="hidden" name="expires" value="1"><input type="hidden" name="active" value="1">
                <button class="btn">Add type</button>
            </form>
        </section>
        <section class="panel" aria-labelledby="h-doc">
            <h2 id="h-doc">Add a document type</h2>
            <form method="post" action="{{ route('admin.document-types.store') }}" class="stacked">
                @csrf
                <div class="row"><x-field name="code" label="Code" required /><x-field name="name" label="Name" required /></div>
                <x-field name="description" label="What it must show" />
                <x-field name="max_size_mb" label="Size limit (MB)" value="10" required />
                <button class="btn secondary">Add document type</button>
            </form>
        </section>
    </div>

    <h2>Program requirements</h2>
    <p class="muted">A student is compliant only when they hold every credential their program requires. Saving recalculates compliance for everyone.</p>
    @foreach ($programs as $p)
        <form method="post" action="{{ route('admin.programs.requirements', $p) }}" class="panel">
            @csrf
            <fieldset><legend><strong>{{ $p->name }}</strong> <span class="muted num">{{ $p->code }}</span></legend>
                <div class="actions">
                    @foreach ($types->where('active', true) as $t)
                        <label class="check"><input type="checkbox" name="types[]" value="{{ $t->id }}" @checked($p->requiredCredentialTypes->contains($t))> {{ $t->name }}</label>
                    @endforeach
                    <button class="btn secondary small">Save</button>
                </div>
            </fieldset>
        </form>
    @endforeach
</x-layouts.app>
