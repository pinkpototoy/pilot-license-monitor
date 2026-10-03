@php($editing = $student->exists)
<x-layouts.app :title="$editing ? 'Edit '.$student->fullName() : 'Add student'">
    <div class="page-head"><h1>{{ $editing ? 'Edit '.$student->fullName() : 'Add student' }}</h1></div>

    @if (session('possible_duplicates'))
        <div class="notice warn" role="alert">
            <strong>This may be a student who already has a record.</strong>
            <ul>@foreach (session('possible_duplicates') as $d)
                <li><a href="{{ route('students.show', $d['id']) }}">{{ $d['name'] }}</a>, <span class="num">{{ $d['number'] }}</span> ({{ $d['status'] }})</li>
            @endforeach</ul>
            <p>If this is a different person, tick "This is a different student" below and save again.</p>
        </div>
    @endif

    <div class="panel">
        <form method="post" action="{{ $editing ? route('students.update', $student) : route('students.store') }}" class="stacked">
            @csrf
            @if ($editing) @method('put') @endif
            <x-field name="student_number" label="Student number" :value="$student->student_number" required />
            <div class="row">
                <x-field name="first_name" label="First name" :value="$student->first_name" required />
                <x-field name="last_name" label="Last name" :value="$student->last_name" required />
            </div>
            <x-field name="middle_name" label="Middle name" :value="$student->middle_name" />
            <x-field name="email" label="Email" type="email" :value="$student->email" required hint="Reminders and the account invitation go here." />
            <div class="row">
                <x-field name="contact_number" label="Contact number" :value="$student->contact_number" hint="Saved as +63 format." />
                <x-field name="date_of_birth" label="Date of birth" type="date" :value="$student->date_of_birth?->format('Y-m-d')" hint="Used only to spot duplicate records." />
            </div>
            <div class="row">
                <x-field name="program_id" label="Program" type="select" hint="Decides which credentials the student must hold.">
                    <option value="">Not assigned yet</option>
                    @foreach ($programs as $p)<option value="{{ $p->id }}" @selected((int) old('program_id', $student->program_id) === $p->id)>{{ $p->name }}</option>@endforeach
                </x-field>
                <x-field name="cohort" label="Cohort or batch" :value="$student->cohort" />
            </div>
            @if ($editing)
                <x-field name="reason" label="Reason for change" type="textarea" hint="Recorded in the audit trail." />
            @endif
            @if (session('possible_duplicates'))
                <label class="check"><input type="checkbox" name="confirm_not_duplicate" value="1"> This is a different student; save anyway</label>
            @endif
            <div class="actions">
                <button class="btn">{{ $editing ? 'Save changes' : 'Add student' }}</button>
                <a href="{{ $editing ? route('students.show', $student) : route('students.index') }}">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
