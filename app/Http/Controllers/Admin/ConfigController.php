<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\CredentialTypeRequirement;
use App\Models\DocumentType;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** UC-06, UC-07 — credential types, checklists, programs, reminder rules and templates (FR-020, FR-050, FR-059). */
class ConfigController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function credentialTypes()
    {
        $this->authorize('configure-system');

        return view('admin.credential-types', [
            'types' => CredentialType::with('requirements.documentType')->orderBy('name')->get(),
            'documentTypes' => DocumentType::orderBy('name')->get(),
            'programs' => Program::with('requiredCredentialTypes')->orderBy('name')->get(),
        ]);
    }

    public function saveCredentialType(Request $request, ?CredentialType $type = null)
    {
        $this->authorize('configure-system');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', Rule::unique('credential_types', 'code')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:120'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'expires' => ['boolean'],
            'default_validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'max_validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'expiring_soon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'number_pattern' => ['nullable', 'string', 'max:200'],
            'active' => ['boolean'],
        ], ['code.regex' => 'Use capital letters, digits and underscores only.']);
        $data['expires'] = $request->boolean('expires');
        $data['active'] = $request->boolean('active');
        foreach (['issuing_authority', 'default_validity_months', 'max_validity_months', 'number_pattern'] as $k) {
            $data[$k] ??= null;
        }
        if ($data['number_pattern'] && @preg_match('/^(?:'.$data['number_pattern'].')$/', '') === false) {
            return back()->withInput()->withErrors(['number_pattern' => 'This is not a valid pattern.']);
        }

        DB::transaction(function () use ($type, $data, $request) {
            // On the create route Laravel injects an empty model; treat that as new.
            $before = $type?->exists ? $type->only(array_keys($data)) : [];
            $type = $type?->exists ? $type : new CredentialType;
            $type->fill($data)->save();
            $this->audit->record($before ? 'config.credential_type_updated' : 'config.credential_type_created', 'credential_type',
                $type->id, null, AuditLogger::diff($before, $type->only(array_keys($data))), null, $request->user());
        });

        return redirect()->route('admin.credential-types')->with('status', 'Credential type saved. New thresholds apply from the next status check (EC-22).');
    }

    public function saveRequirement(Request $request, CredentialType $type)
    {
        $this->authorize('configure-system');
        $data = $request->validate([
            'document_type_id' => ['required', 'exists:document_types,id'],
            'mandatory' => ['boolean'],
            'remove' => ['boolean'],
        ]);
        $req = CredentialTypeRequirement::firstOrNew(['credential_type_id' => $type->id, 'document_type_id' => $data['document_type_id']]);
        if ($request->boolean('remove')) {
            if ($req->exists && DB::table('documents')->where('requirement_id', $req->id)->exists()) {
                return back()->withErrors(['document_type_id' => 'Documents were already uploaded for this item, so it can only be made optional, not removed.']);
            }
            $req->exists && $req->delete();
            $action = 'removed';
        } else {
            $req->mandatory = $request->boolean('mandatory');
            $req->sort_order ??= (int) CredentialTypeRequirement::where('credential_type_id', $type->id)->max('sort_order') + 1;
            $req->save();
            $action = 'saved';
        }
        $this->audit->record("config.checklist_{$action}", 'credential_type', $type->id, null,
            ['document_type_id' => ['old' => null, 'new' => (int) $data['document_type_id']], 'mandatory' => ['old' => null, 'new' => $request->boolean('mandatory')]], null, $request->user());

        return back()->with('status', 'Checklist updated.');
    }

    public function saveProgramRequirements(Request $request, Program $program)
    {
        $this->authorize('configure-system');
        $ids = array_map('intval', (array) $request->input('types', []));
        $old = $program->requiredCredentialTypes()->pluck('credential_types.id')->all();
        $program->requiredCredentialTypes()->sync($ids);
        $this->audit->record('config.program_requirements', 'program', $program->id, null,
            ['required_types' => ['old' => $old, 'new' => $ids]], null, $request->user());
        Artisan::call('compliance:evaluate');

        return back()->with('status', "Requirements for {$program->name} saved and compliance recalculated.");
    }

    public function saveDocumentType(Request $request)
    {
        $this->authorize('configure-system');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', 'unique:document_types,code'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'max_size_mb' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $doc = DocumentType::create($data + ['accepted_mime_types' => array_keys(config('splms.documents.allowed'))]);
        $this->audit->record('config.document_type_created', 'document_type', $doc->id, null, null, $doc->name, $request->user());

        return back()->with('status', 'Document type added.');
    }

    public function reminders()
    {
        $this->authorize('configure-system');

        return view('admin.reminders', [
            'rules' => NotificationRule::with('credentialType')->orderBy('offset_days')->get(),
            'types' => CredentialType::orderBy('name')->get(),
            'templates' => NotificationTemplate::where('active', true)->orderBy('code')->orderBy('channel')->get(),
            'placeholders' => TemplateRenderer::PLACEHOLDERS,
        ]);
    }

    public function saveRule(Request $request, ?NotificationRule $rule = null)
    {
        $this->authorize('configure-system');
        if ($request->boolean('delete') && $rule?->exists) {
            $rule->update(['active' => false]);
            $this->audit->record('config.reminder_rule_disabled', 'notification_rule', $rule->id, null, null, $rule->describe(), $request->user());

            return back()->with('status', 'Reminder turned off.');
        }
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'when' => ['required', Rule::in(['before', 'on', 'after'])],
            'credential_type_id' => ['nullable', 'exists:credential_types,id'],
            'recipients' => ['required', Rule::in(['student', 'staff', 'both'])],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['email', 'in_app', 'sms'])],
        ], ['channels.required' => 'Choose at least one channel.']);
        $offset = match ($data['when']) {
            'before' => -$data['days'], 'on' => 0, 'after' => $data['days']
        };

        // Self-review #6: prevent duplicate rules and rules that would do nothing.
        $dupe = NotificationRule::where('active', true)->where('offset_days', $offset)
            ->where('credential_type_id', $data['credential_type_id'] ?? null)->when($rule?->exists, fn ($q) => $q->where('id', '<>', $rule->id))->exists();
        if ($dupe) {
            return back()->withInput()->withErrors(['days' => 'A reminder for this day already exists.']);
        }
        $rule = $rule?->exists ? $rule : new NotificationRule(['template_code' => 'expiry_reminder']);
        $before = $rule->exists ? $rule->only(['offset_days', 'recipients', 'channels']) : [];
        $rule->fill(['offset_days' => $offset, 'credential_type_id' => $data['credential_type_id'] ?? null,
            'recipients' => $data['recipients'], 'channels' => array_values($data['channels']), 'active' => true])->save();
        $this->audit->record('config.reminder_rule_saved', 'notification_rule', $rule->id, null,
            AuditLogger::diff($before, $rule->only(['offset_days', 'recipients', 'channels'])), $rule->describe(), $request->user());

        return back()->with('status', 'Reminder saved: '.$rule->describe().'. It applies from the next daily run (AC-06).');
    }

    public function saveTemplate(Request $request, NotificationTemplate $template)
    {
        $this->authorize('configure-system');
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        // Templates are versioned: the old version is kept for the record.
        DB::transaction(function () use ($template, $data, $request) {
            $template->update(['active' => false]);
            $new = NotificationTemplate::create(['code' => $template->code, 'channel' => $template->channel,
                'subject' => $data['subject'], 'body' => $data['body'], 'version' => $template->version + 1, 'active' => true]);
            $this->audit->record('config.template_updated', 'notification_template', $new->id, null,
                AuditLogger::diff(['subject' => $template->subject, 'body' => $template->body], $data), "Version {$new->version}", $request->user());
        });

        return back()->with('status', 'Template saved as a new version.');
    }
}
