<?php

namespace Database\Seeders;

use App\Models\CredentialType;
use App\Models\DocumentType;
use App\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reference data needed in every environment.
 *
 * IMPORTANT: credential types, validity periods, number formats and document checklists
 * below are PLACEHOLDERS to be replaced with values confirmed by the organization and the
 * aviation authority (SRS Appendix E, questions 1–8). They are not regulatory statements.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $docs = collect([
            ['RENEWED_LICENSE', 'Copy of renewed license', 'Clear copy of both sides of the renewed license.'],
            ['MEDICAL_CERT', 'Medical certificate', 'Copy of the current medical certificate.'],
            ['GOV_ID', 'Valid government ID', 'Any valid government-issued photo ID.'],
            ['RECEIPT', 'Official receipt', 'Receipt of the renewal fee, if applicable.'],
        ])->mapWithKeys(fn ($d) => [$d[0] => DocumentType::updateOrCreate(['code' => $d[0]], [
            'name' => $d[1], 'description' => $d[2],
            'accepted_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_size_mb' => 10,
        ])]);

        $types = [
            'SPL' => ['Student Pilot License', true, 24, 60, 30, ['RENEWED_LICENSE' => true, 'GOV_ID' => true, 'RECEIPT' => false]],
            'MED' => ['Medical Certificate', true, 12, 60, 30, ['MEDICAL_CERT' => true, 'GOV_ID' => true]],
            'RTP' => ['Radio Telephony Permit', true, 60, 120, 60, ['RENEWED_LICENSE' => true]],
        ];
        foreach ($types as $code => [$name, $expires, $validity, $max, $soon, $checklist]) {
            $type = CredentialType::updateOrCreate(['code' => $code], [
                'name' => $name, 'issuing_authority' => 'Civil Aviation Authority of the Philippines (confirm)',
                'expires' => $expires, 'default_validity_months' => $validity, 'max_validity_months' => $max,
                'expiring_soon_days' => $soon, 'number_pattern' => null, 'active' => true,
            ]);
            $i = 0;
            foreach ($checklist as $docCode => $mandatory) {
                $type->requirements()->updateOrCreate(['document_type_id' => $docs[$docCode]->id], ['mandatory' => $mandatory, 'sort_order' => $i++]);
            }
        }

        $ppl = Program::updateOrCreate(['code' => 'PPL'], ['name' => 'Private Pilot License course', 'active' => true]);
        $cpl = Program::updateOrCreate(['code' => 'CPL'], ['name' => 'Commercial Pilot License course', 'active' => true]);
        $ids = CredentialType::pluck('id', 'code');
        $ppl->requiredCredentialTypes()->sync([$ids['SPL'], $ids['MED']]);
        $cpl->requiredCredentialTypes()->sync([$ids['SPL'], $ids['MED'], $ids['RTP']]);

        foreach ([
            ['ILLEGIBLE', 'Illegible', false], ['EXPIRED_DOC', 'Expired document', false],
            ['WRONG_DOC', 'Wrong document', false], ['NAME_MISMATCH', 'Name mismatch', false],
            ['INCOMPLETE_PAGES', 'Incomplete pages', false], ['OTHER', 'Other', true],
        ] as [$code, $label, $note]) {
            DB::table('rejection_reasons')->updateOrInsert(['code' => $code], ['label' => $label, 'requires_note' => $note, 'active' => true]);
        }

        // FR-059 — editable message templates. In-app messages fall back to the email wording.
        $templates = [
            ['expiry_reminder', 'email', 'Your {{credential_name}} expires {{days_text}} ({{expiry_date}})',
                "Hello {{student_first_name}},\n\nYour {{credential_name}} ({{license_number}}) expires on {{expiry_date}}, {{days_text}}.\n\n{{action_line}}\n\nSign in: {{app_url}}"],
            ['expiry_reminder', 'in_app', '{{credential_name}} expires {{days_text}}', '{{action_line}}'],
            ['expiry_reminder_staff', 'in_app', '{{student_name}}: {{credential_name}} expires {{days_text}}', '{{action_line}}'],
            ['renewal_submitted', 'email', 'We received your {{credential_name}} renewal',
                "Hello {{student_first_name}},\n\nYour renewal documents for {{credential_name}} were submitted and are waiting for verification. We'll email you when they have been reviewed.\n\nTrack progress: {{app_url}}"],
            ['renewal_submitted', 'in_app', 'Renewal submitted: {{credential_name}}', 'Your documents are waiting for verification.'],
            ['renewal_submitted_staff', 'in_app', 'To verify: {{student_name}}, {{credential_name}}', '{{student_name}} ({{student_number}}) submitted renewal documents ({{case_status}}).'],
            ['case_needs_correction', 'email', 'Action needed: correct your {{credential_name}} documents',
                "Hello {{student_first_name}},\n\nSome documents for your {{credential_name}} renewal need to be corrected:\n\n{{reasons}}\n\nUpload a corrected copy of each, then submit again: {{app_url}}"],
            ['case_needs_correction', 'in_app', 'Correct your {{credential_name}} documents', '{{reasons}}'],
            ['case_approved', 'email', 'Your {{credential_name}} documents were approved',
                "Hello {{student_first_name}},\n\nAll documents for your {{credential_name}} renewal were approved. The records office will now record your new validity dates.\n\n{{app_url}}"],
            ['case_approved', 'in_app', 'Documents approved: {{credential_name}}', 'The records office will record your new dates shortly.'],
            ['case_completed', 'email', 'Your {{credential_name}} renewal is complete',
                "Hello {{student_first_name}},\n\nYour {{credential_name}} ({{license_number}}) is now recorded as valid until {{expiry_date}}.\n\n{{app_url}}"],
            ['case_completed', 'in_app', 'Renewal complete: {{credential_name}}', 'Valid until {{expiry_date}}.'],
            ['draft_idle', 'email', 'Finish your {{credential_name}} renewal',
                "Hello {{student_first_name}},\n\nYou started a renewal for your {{credential_name}} but haven't submitted it. Upload the remaining documents and submit: {{app_url}}"],
            ['correction_idle', 'email', 'Reminder: your {{credential_name}} documents still need correcting',
                "Hello {{student_first_name}},\n\nYour {{credential_name}} renewal is waiting for corrected documents. Sign in to see what to fix: {{app_url}}"],
            ['draft_cancel_warning', 'email', 'Your {{credential_name}} renewal draft will be cancelled',
                "Hello {{student_first_name}},\n\n{{action_line}}\n\n{{app_url}}"],
            ['upload_infected', 'email', 'A file you uploaded was blocked', "Hello {{student_first_name}},\n\n{{action_line}}"],
            ['upload_infected_staff', 'in_app', 'Blocked upload: {{student_name}}', '{{action_line}}'],
            ['delivery_failure', 'in_app', 'A message could not be delivered', '{{action_line}}'],
            ['staff_digest', 'email', 'Daily compliance digest, {{digest_date}}', "Today's follow-ups:\n\n{{action_line}}\n\nOpen the dashboard: {{app_url}}"],
        ];
        foreach ($templates as [$code, $channel, $subject, $body]) {
            DB::table('notification_templates')->updateOrInsert(['code' => $code, 'channel' => $channel, 'version' => 1], [
                'subject' => $subject, 'body' => $body, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (DB::table('notification_rules')->count() === 0) {
            foreach ([-90, -60, -30, -14, -7, 0, 7] as $offset) {
                DB::table('notification_rules')->insert([
                    'credential_type_id' => null, 'offset_days' => $offset,
                    'recipients' => in_array($offset, [-7, 0, 7], true) ? 'both' : 'student',
                    'channels' => json_encode(['email', 'in_app']), 'template_code' => 'expiry_reminder',
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
