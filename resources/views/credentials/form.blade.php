@php
    $titles = ['create' => 'Record a credential', 'correct' => 'Correct credential details', 'period' => 'Record renewed dates'];
    $action = match ($mode) {
        'create' => route('credentials.store', $student),
        'correct' => url("/credentials/{$credential->id}/correct"),
        'period' => url("/credentials/{$credential->id}/periods"),
    };
    $p = $credential?->currentPeriod;
@endphp
<x-layouts.app :title="$titles[$mode]">
    <div class="page-head">
        <div><h1>{{ $titles[$mode] }}</h1><p class="muted">{{ $student->fullName() }} · <span class="num">{{ $student->student_number }}</span>@if($credential) · {{ $credential->type->name }}@endif</p></div>
    </div>

    <div class="panel">
        @if ($mode === 'correct')
            <p>Use this to fix a typing mistake in the current period. To record a renewal, use <a href="{{ route('credentials.period', $credential) }}">Record renewed dates</a> instead, so the old period stays in the history.</p>
        @elseif ($mode === 'period')
            <p>Enter the dates from the renewed license or certificate. The current period (expires {{ $p?->expiry_date?->format('d M Y') ?? 'unknown' }}) is kept in the history.</p>
        @endif

        <form method="post" action="{{ $action }}" class="stacked">
            @csrf
            @if ($mode === 'correct') @method('put') @endif

            @if ($mode === 'create')
                @if ($types->isEmpty())
                    <p class="empty">This student already has a current credential of every active type.</p>
                @endif
                <x-field name="credential_type_id" label="Credential type" type="select" required>
                    <option value="">Choose a type</option>
                    @foreach ($types as $t)<option value="{{ $t->id }}" @selected((int) old('credential_type_id') === $t->id)>{{ $t->name }}</option>@endforeach
                </x-field>
            @endif

            <x-field name="license_number" label="License or certificate number" :value="$mode === 'correct' ? $credential?->license_number : ($mode === 'period' ? $credential?->license_number : null)"
                hint="{{ $mode === 'period' ? 'Change it only if the renewed document has a new number.' : 'As printed on the document.' }}" />
            <div class="row">
                <x-field name="issue_date" label="Issue date" type="date" :value="$mode === 'correct' ? $p?->issue_date?->format('Y-m-d') : null" />
                <x-field name="expiry_date" label="Expiry date" type="date" :value="$mode === 'correct' ? $p?->expiry_date?->format('Y-m-d') : null"
                    hint="Leave empty only if unknown. The credential will show as Incomplete until it is added." />
            </div>
            @if ($mode !== 'create')
                <x-field name="reason" label="Reason" type="textarea" :required="$mode === 'correct'" hint="Recorded in the audit trail{{ $mode === 'correct' ? ' (required)' : '' }}." />
            @endif
            @if (session('needs_confirmation'))
                <label class="check"><input type="checkbox" name="confirmed" value="1"> I checked the dates against the document; save them anyway</label>
            @endif
            <div class="actions">
                <button class="btn">{{ ['create' => 'Record credential', 'correct' => 'Save correction', 'period' => 'Record renewed dates'][$mode] }}</button>
                <a href="{{ route('students.show', $student) }}">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
