# Working on this codebase

This is a Laravel 13 + PostgreSQL app built to an SRS (see README). The rules below keep it consistent.

- Business rules live in `app/Domain/*` services, never in controllers. Throw `DomainRuleViolation`
  with the SRS rule ID in the message (for example "BR-014: …"). It renders as a field error, or as RFC 9457 JSON for API requests.
- Every data-changing service method writes an audit entry via `AuditLogger::record()` inside the same
  DB transaction. Never update or delete `audit_logs`; a trigger blocks it.
- `credentials.current_status` and `students.compliance_state` are written ONLY by `ComplianceEngine`.
  After changing dates or overrides, call `ComplianceEngine::evaluateStudent()`.
- "Today" is `App\Domain\Clock::today()` (Asia/Manila). Never use `now()` for date rules.
- Renewals add a period with `CredentialService::addPeriod()`; never overwrite old dates.
- Authorization: policies in `app/Policies` follow the SRS 9 matrix. Staff-side routes sit behind the `staff` middleware, which returns 404 to students.
- Status display: always use `<x-badge :status="…"/>`, which shows a text label, colour and shape (UX-01).
- CSP forbids inline styles and scripts: put styles in `public/css/app.css` and don't use `style=""` attributes.
- Tests run on PostgreSQL (`splms_test`). Name tests after SRS IDs. Run `php artisan test` before finishing.
- Renewal transitions go only through `RenewalService` (it locks the row, writes a case event, audits, notifies).
- Notifications: insert through `NotificationService` only; every message needs a stable dedupe key.
- Blade gotchas found in this codebase: a directive needs a non-letter before `@` (`exports @endif`, not
  `exports@endif`), and never mix one-line `@php(...)` with a later `@php … @endphp` block in the same file.
- Phases 1–5 are complete. Future work is listed in the README under "Not built".
