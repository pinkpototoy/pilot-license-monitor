<x-layouts.app title="Students">
    <div class="page-head">
        <h1>Students</h1>
        @can('create', \App\Models\Student::class)<a class="btn" href="{{ route('students.create') }}">Add student</a>@endcan
    </div>

    <form method="get" class="filters" role="search" aria-label="Filter students">
        <div class="field grow">
            <label for="q">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, student number, email or license number">
        </div>
        <div class="field">
            <label for="compliance">Compliance</label>
            <select id="compliance" name="compliance">
                <option value="">Any</option>
                @foreach (\App\Enums\ComplianceState::cases() as $c)
                    <option value="{{ $c->value }}" @selected(($filters['compliance'] ?? '') === $c->value)>{{ $c->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="credential_status">Has a credential that is</label>
            <select id="credential_status" name="credential_status">
                <option value="">Any status</option>
                @foreach (\App\Enums\ValidityStatus::cases() as $v)
                    <option value="{{ $v->value }}" @selected(($filters['credential_status'] ?? '') === $v->value)>{{ $v->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="program">Program</label>
            <select id="program" name="program">
                <option value="">All programs</option>
                @foreach ($programs as $p)<option value="{{ $p->id }}" @selected((int)($filters['program'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Record</label>
            <select id="status" name="status">
                @foreach (['active' => 'Active', 'on_leave' => 'On leave', 'deactivated' => 'Deactivated', 'graduated' => 'Graduated', 'withdrawn' => 'Withdrawn', 'all' => 'All records'] as $v => $l)
                    <option value="{{ $v }}" @selected(($filters['status'] ?? 'active') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="sort">Sort by</label>
            <select id="sort" name="sort">
                <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Name</option>
                <option value="number" @selected(($filters['sort'] ?? '') === 'number')>Student number</option>
                <option value="compliance" @selected(($filters['sort'] ?? '') === 'compliance')>Most urgent first</option>
            </select>
        </div>
        <button class="btn secondary">Apply</button>
    </form>

    @if ($students->isEmpty())
        <p class="empty">No students match these filters. Clear the search or choose "All records".</p>
    @else
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Students, {{ $students->total() }} results</caption>
                <thead><tr><th scope="col">Student</th><th scope="col">Number</th><th scope="col">Program</th><th scope="col">Compliance</th><th scope="col">Credentials</th></tr></thead>
                <tbody>
                @foreach ($students as $s)
                    <tr>
                        <td><a href="{{ route('students.show', $s) }}">{{ $s->fullName() }}</a>@if($s->status->value !== 'active')<br><span class="muted">{{ $s->status->label() }}</span>@endif</td>
                        <td class="num">{{ $s->student_number }}</td>
                        <td>@if($s->program)<abbr title="{{ $s->program->name }}">{{ $s->program->code }}</abbr>@else<span class="muted">None</span>@endif</td>
                        <td><x-badge :status="$s->compliance_state" /></td>
                        @php
                            $held = $s->currentCredentials->sortBy(fn ($c) => $c->type->code);
                            $missing = $s->status->isMonitored() && $s->program
                                ? $s->program->requiredCredentialTypes->whereNotIn('id', $held->pluck('credential_type_id'))->sortBy('code')
                                : collect();
                        @endphp
                        <td><div class="stack">
                            @foreach ($held as $c)
                                <span class="pair" title="{{ $c->type->name }}"><span class="code">{{ $c->type->code }}</span><span class="visually-hidden">{{ $c->type->name }}:</span><x-badge :status="$c->current_status" /></span>
                            @endforeach
                            @foreach ($missing as $t)
                                <span class="pair" title="{{ $t->name }} is required but not recorded"><span class="code">{{ $t->code }}</span><span class="badge red">Missing</span></span>
                            @endforeach
                            @if ($held->isEmpty() && $missing->isEmpty())<span class="muted">None recorded</span>@endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span class="muted">{{ $students->firstItem() }}–{{ $students->lastItem() }} of {{ $students->total() }}</span>
            @if ($students->previousPageUrl())<a class="btn secondary small" href="{{ $students->previousPageUrl() }}">Previous</a>@endif
            @if ($students->nextPageUrl())<a class="btn secondary small" href="{{ $students->nextPageUrl() }}">Next</a>@endif
        </div>
    @endif
</x-layouts.app>
