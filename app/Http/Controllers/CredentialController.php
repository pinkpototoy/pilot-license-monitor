<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\ComplianceEngine;
use App\Domain\Records\CredentialService;
use App\Enums\PeriodSource;
use App\Enums\ValidityStatus;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CredentialController extends Controller
{
    public function create(Student $student)
    {
        $this->authorize('create', Credential::class);
        $held = $student->currentCredentials()->pluck('credential_type_id');

        return view('credentials.form', [
            'mode' => 'create',
            'student' => $student,
            'credential' => null,
            'types' => CredentialType::where('active', true)->whereNotIn('id', $held)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Student $student, CredentialService $service)
    {
        $this->authorize('create', Credential::class);
        $data = $request->validate([
            'credential_type_id' => ['required', Rule::exists('credential_types', 'id')->where('active', true)],
            'license_number' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['nullable', 'string', 'max:10'],
            'expiry_date' => ['nullable', 'string', 'max:10'],
        ]);
        $service->record($student, CredentialType::findOrFail($data['credential_type_id']),
            $data['license_number'] ?? null, $data['issue_date'] ?? null, $data['expiry_date'] ?? null,
            $request->user(), PeriodSource::Manual, $request->boolean('confirmed'));

        return redirect()->route('students.show', $student)->with('status', 'Credential recorded and status calculated.');
    }

    public function correctForm(Credential $credential)
    {
        $this->authorize('update', $credential);
        $credential->load(['student', 'type', 'currentPeriod']);

        return view('credentials.form', ['mode' => 'correct', 'student' => $credential->student, 'credential' => $credential, 'types' => collect()]);
    }

    public function correct(Request $request, Credential $credential, CredentialService $service)
    {
        $this->authorize('update', $credential);
        $data = $this->periodRules($request, requireReason: true);
        $service->correctCurrentPeriod($credential, $data['license_number'] ?? null, $data['issue_date'] ?? null,
            $data['expiry_date'] ?? null, $data['reason'], $request->user(), $request->boolean('confirmed'));

        return redirect()->route('students.show', $credential->student_id)->with('status', 'Correction saved and recorded in the audit trail.');
    }

    public function periodForm(Credential $credential)
    {
        $this->authorize('update', $credential);
        $credential->load(['student', 'type', 'currentPeriod']);

        return view('credentials.form', ['mode' => 'period', 'student' => $credential->student, 'credential' => $credential, 'types' => collect()]);
    }

    public function addPeriod(Request $request, Credential $credential, CredentialService $service)
    {
        $this->authorize('update', $credential);
        $data = $this->periodRules($request, requireReason: false);
        $service->addPeriod($credential, $data['license_number'] ?? null, $data['issue_date'] ?? null, $data['expiry_date'] ?? null,
            $request->user(), PeriodSource::Manual, null, $data['reason'] ?? null, $request->boolean('confirmed'));

        return redirect()->route('students.show', $credential->student_id)->with('status', 'New validity period recorded. The previous period is kept in the history.');
    }

    public function override(Request $request, Credential $credential, CredentialService $service)
    {
        $this->authorize('override', $credential);
        $data = $request->validate([
            'override_status' => ['required', Rule::enum(ValidityStatus::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $service->applyOverride($credential, ValidityStatus::from($data['override_status']), $data['reason'], $data['valid_until'] ?? null, $request->user());

        return back()->with('status', 'Override applied. It is shown on the record and in the audit trail.');
    }

    public function endOverride(Request $request, Credential $credential, CredentialService $service)
    {
        $this->authorize('override', $credential);
        $service->endOverride($credential, $request->user(), 'Ended by Admin');
        app(ComplianceEngine::class)->evaluateStudent($credential->student);

        return back()->with('status', 'Override ended; status recalculated from dates.');
    }

    private function periodRules(Request $request, bool $requireReason): array
    {
        return $request->validate([
            'license_number' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['nullable', 'string', 'max:10'],
            'expiry_date' => ['nullable', 'string', 'max:10'],
            'reason' => [$requireReason ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
    }
}
