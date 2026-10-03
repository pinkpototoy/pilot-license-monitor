# Pilot License Monitor

Student Pilot License Monitoring and Compliance Management System, built to the
*System Proposal and Software Requirements Specification* (SRS) v1.0.

This release completes the SRS roadmap through **Phase 5**: records, the compliance engine,
renewals with document verification, notifications, reports and the Google Sheets import.

## What works

| Area | Delivered | SRS reference |
|---|---|---|
| Sign-in and access | Argon2id passwords, lockout, TOTP two-step verification for staff, timeouts, invitations, the SRS 9 permission matrix | FR-001 to FR-008, AC-01 to AC-03 |
| Student records | Create, edit, deactivate, duplicate warnings, search and filters | FR-010 to FR-015 |
| Credentials | Configurable types, validity-period history, corrections with reason, Admin overrides | FR-020 to FR-027, BR-010 to BR-018 |
| Compliance engine | Status from dates (Manila time), per-student compliance, nightly run, daily snapshots | SRS 13, 16 |
| Renewals | Draft → Submitted → Verification → Approved → Completed; corrections and resubmission; review locks; separation of duties | SRS 13.2, 14, UC-11 to UC-14 |
| Documents | Content-based type checks, size limits, encrypted/broken PDF detection, virus scanning with quarantine, private storage, audited viewing | FR-032 to FR-038, BR-023, EC-18 |
| Notifications | Configurable reminder rules, event messages, email + in-app inbox, no duplicates, retries, staff digest, preferences, editable templates | SRS 15, FR-050 to FR-059 |
| Reports | Ten reports with filters; CSV and PDF exports marked confidential and audited; point-in-time compliance | SRS 29, FR-060 to FR-065 |
| Import | Google Sheets/CSV import with header and spelling recognition, date-convention handling, row-level results, Admin commit, 7-day rollback | SRS 28, UC-04 |
| Administration | Users (two-Admin minimum), credential types and checklists, program requirements, reminders and templates | UC-02, UC-06, UC-07, BR-043 |
| Audit log | Append-only (database trigger), hash chain verified daily, readable history | SRS 27 |

## Not built (future enhancements, SRS Appendix B)

SMS delivery (an adapter is in place, switched off), single sign-on, analytics dashboards, a student merge tool,
OCR of uploaded documents, multi-school support and any aviation authority integration.

## Requirements

- PHP 8.3+ with the extensions `pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl` (Argon2 support is built in)
- Composer 2
- PostgreSQL 14+ (16 recommended). SQLite and MySQL are **not** supported: the schema uses
  partial unique indexes, JSONB and triggers.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the databases (adjust to your PostgreSQL setup)
createuser -P splms                 # choose a password and put it in .env (DB_PASSWORD)
createdb -O splms splms
createdb -O splms splms_test        # used by the test suite

php artisan migrate --seed          # reference data + synthetic demo students
php artisan serve
```

Open http://localhost:8000 and sign in with a demo account (password `demo-password-123`):

| Account | Role | What to try |
|---|---|---|
| admin@example.test | Admin | Everything, including Administration and the audit log |
| admin2@example.test | Admin | Has one renewal locked for review |
| records@example.test | Staff | Verification queue, reports, import |
| student@example.test | Student | A submitted renewal; inbox |

The demo data includes renewals in every state, with sample PDF files. Students' own demo accounts use their
seeded email address with the same password; one of them has a renewal that needs correction.

Staff accounts set up two-step verification at first sign-in. Use any authenticator app.
Emails (lockout notices, invitations, password resets) are written to `storage/logs/laravel.log` while `MAIL_MAILER=log`.

**Production:** never run the demo seeder. Instead, run `php artisan db:seed --class=ReferenceDataSeeder`, then create the first two Admins with
`php artisan splms:create-user you@school.edu "Your Name" --role=admin`.

## Scheduled jobs

Add one cron entry: `* * * * * php /path/to/artisan schedule:run`. It runs:

| Command | When (Asia/Manila) | Purpose |
|---|---|---|
| `compliance:evaluate` | 00:05 daily | Recalculate all statuses, write the daily snapshot |
| `audit:verify` | 01:00 daily | Check the audit hash chain; exits non-zero and logs critical if broken |
| `renewals:housekeeping` | 05:30 daily | Warn about drafts idle 45 days, cancel at 60 (BR-027) |
| `notifications:schedule` | 06:00 daily | Queue due reminders and idle-renewal nudges |
| `notifications:send` | every minute | Deliver queued messages, retrying failures |
| `notifications:digest` | 08:00 weekdays | Staff digest email |
| `queue:work --stop-when-empty` | every minute | Background jobs (virus scans) when `QUEUE_CONNECTION=database` |

For local development, `.env.example` sets `QUEUE_CONNECTION=sync`, so scans run immediately and no worker is needed.
In production, set `QUEUE_CONNECTION=database`, `SPLMS_SCANNER=clamav` and real SMTP settings.

Both commands can be run by hand. `compliance:evaluate --date=2026-12-01` evaluates as of a given date, for testing or catch-up.

## Tests

```bash
php artisan test
```

There are 122 tests. They run against the `splms_test` PostgreSQL database and are named after the SRS IDs they prove (for example `test_ac04_…` and `test_br014_…`).

## Where things live

```
app/Domain/Compliance/   ValidityCalculator (pure date rules), ComplianceEvaluator, ComplianceEngine
app/Domain/Records/      StudentService, CredentialService: all business rules, audited
app/Domain/Audit/        AuditLogger (append-only, hash chain)
app/Domain/Auth/         LoginService, MfaService, InvitationService, UserAdminService
app/Domain/Renewals/     RenewalService: the renewal workflow state machine
app/Domain/Documents/    DocumentInspector (upload checks), Scanner (ClamAV / development)
app/Domain/Notifications NotificationService, channel senders, TemplateRenderer
app/Domain/Reports/      ReportService (RPT-01 to RPT-10), ReportExporter (CSV/PDF)
app/Domain/Import/       ImportService (prepare, commit, rollback)
app/Http/                Thin controllers, middleware (MFA gate, timeouts, headers, request IDs)
app/Policies/            SRS 9 permission matrix
config/splms.php         Time zone, thresholds, lockout and session settings
database/migrations/     Schema (SRS 22)
database/seeders/        ReferenceDataSeeder (placeholders, see below), DemoSeeder (synthetic)
public/css/app.css       The whole design system (no build step)
```

## Values that still need confirmation

The credential types (SPL, MED, RTP), validity periods, number formats, document checklists and
program requirements in `ReferenceDataSeeder` are **placeholders**. They are not regulatory statements and must be replaced with values confirmed by the organization and the aviation authority (SRS Appendix E, questions 1–8). The same applies to `expiry_date_is_valid_day` in `config/splms.php` (Appendix E, question 5).

## Deviations from the SRS (to fold back into the document)

1. **SRS 13.1 threshold wording.** The code treats *days remaining ≤ threshold* as Expiring Soon, to satisfy AC-04 and the "Expiring within 30 days" dashboard card.
2. **`credential_periods.license_number`** was added so that a number change on renewal keeps history (EC-16).
3. **`job_runs`** was added to prove the nightly job ran (NFR-018).
4. **`user_sessions`** is implemented as Laravel's `sessions` table (database session driver).
5. **Students without a program** are treated as *Data Issue*, because the system can't know which credentials are required (self-review #7).
6. The **initial status calculation** of a new record isn't logged as a separate change; the creation entry already records it.
7. **Document decisions are notified per case, not per document.** The student gets one message when the case
   needs correction (listing every reason) or is approved, instead of one email per document.
8. **Staff receive reminders in-app only**, plus one weekday digest email, to avoid flooding their inboxes.
9. **Students without an account** still receive email reminders at the address on their record.
10. **Reviewers' names are hidden from students**, as SRS 35 suggests. Change this in `renewals/show.blade.php` if the organization prefers otherwise.
