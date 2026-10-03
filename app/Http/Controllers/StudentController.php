<?php

namespace App\Http\Controllers;

use App\Domain\Auth\InvitationService;
use App\Domain\Clock;
use App\Domain\Records\StudentService;
use App\Http\Requests\StudentRequest;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /** FR-013: search and filter. Filters live in the URL so views can be shared (UX-08). */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Student::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'compliance' => ['nullable', 'in:compliant,at_risk,non_compliant,data_issue,not_monitored'],
            'status' => ['nullable', 'in:active,on_leave,graduated,withdrawn,deactivated,all'],
            'program' => ['nullable', 'integer'],
            'credential_status' => ['nullable', 'in:active,expiring_soon,expired,incomplete_data,not_yet_valid'],
            'sort' => ['nullable', 'in:name,number,compliance'],
        ]);

        $students = Student::query()
            ->with(['program.requiredCredentialTypes', 'currentCredentials.type', 'currentCredentials.currentPeriod'])
            ->search($filters['q'] ?? null)
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('status', $filters['status'] ?? 'active'))
            ->when($filters['compliance'] ?? null, fn ($q, $v) => $q->where('compliance_state', $v))
            ->when($filters['program'] ?? null, fn ($q, $v) => $q->where('program_id', $v))
            ->when($filters['credential_status'] ?? null, fn ($q, $v) => $q->whereHas('currentCredentials', fn ($c) => $c->where('current_status', $v)))
            ->when(($filters['sort'] ?? 'name') === 'number', fn ($q) => $q->orderBy('student_number'))
            ->when(($filters['sort'] ?? 'name') === 'compliance', fn ($q) => $q->orderByRaw(
                "array_position(ARRAY['non_compliant','data_issue','at_risk','compliant','not_monitored']::varchar[], compliance_state)"))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25)->withQueryString();

        return view('students.index', [
            'students' => $students,
            'filters' => $filters,
            'programs' => Program::orderBy('name')->get(),
            'today' => Clock::today(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Student::class);

        return view('students.form', ['student' => new Student, 'programs' => Program::where('active', true)->orderBy('name')->get()]);
    }

    public function store(StudentRequest $request, StudentService $service)
    {
        $this->authorize('create', Student::class);

        // FR-012: warn about probable duplicates before saving, unless already confirmed.
        if (! $request->boolean('confirm_not_duplicate')) {
            $dupes = $service->possibleDuplicates($request->validated());
            if ($dupes->isNotEmpty()) {
                return back()->withInput()->with('possible_duplicates', $dupes->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->fullName(), 'number' => $s->student_number, 'status' => $s->status->label(),
                ])->all());
            }
        }

        $student = $service->create($request->validated(), $request->user());

        return redirect()->route('students.show', $student)->with('status', "Student {$student->fullName()} added.");
    }

    public function show(Student $student)
    {
        $this->authorize('view', $student);
        $student->load([
            'program.requiredCredentialTypes', 'user',
            'credentials' => fn ($q) => $q->with(['type', 'periods', 'activeOverride.creator', 'openRenewalCase'])->orderByRaw('archived_at IS NOT NULL'),
        ]);

        return view('students.show', [
            'student' => $student,
            'today' => Clock::today(),
            'history' => AuditLog::with('actor')->where('student_id', $student->id)->orderByDesc('id')->limit(10)->get(),
            'missingTypes' => $student->program?->requiredCredentialTypes
                ->reject(fn ($t) => $student->credentials->whereNull('archived_at')->contains('credential_type_id', $t->id)) ?? collect(),
        ]);
    }

    public function edit(Student $student)
    {
        $this->authorize('update', $student);

        return view('students.form', ['student' => $student, 'programs' => Program::orderBy('name')->get()]);
    }

    public function update(StudentRequest $request, Student $student, StudentService $service)
    {
        $this->authorize('update', $student);
        $service->update($student, $request->validated(), $request->user(), $request->input('reason'));

        return redirect()->route('students.show', $student)->with('status', 'Changes saved.');
    }

    public function deactivate(Request $request, Student $student, StudentService $service)
    {
        $this->authorize('deactivate', $student);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $service->deactivate($student, $request->user(), $data['reason']);

        return redirect()->route('students.show', $student)->with('status', 'Student deactivated. Reminders stopped and sign-in blocked; records are kept.');
    }

    public function reactivate(Request $request, Student $student, StudentService $service)
    {
        $this->authorize('deactivate', $student);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $service->reactivate($student, $request->user(), $data['reason']);

        return redirect()->route('students.show', $student)->with('status', 'Student reactivated.');
    }

    public function invite(Request $request, Student $student, InvitationService $invitations)
    {
        $this->authorize('invite', $student);
        $invitations->inviteStudent($student, $request->user());

        return back()->with('status', "Invitation sent to {$student->email}.");
    }
}
