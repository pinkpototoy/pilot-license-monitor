<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.2 students, 22.3 credentials, 22.6 import batches (needed by students FK). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('file_sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('validating');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestampsTz();
            $table->timestampTz('committed_at')->nullable();
        });
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_status_check CHECK (status IN ('validating','ready','committed','rolled_back','failed'))");

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_data');
            $table->string('status', 20);
            $table->jsonb('messages')->nullable();
            $table->jsonb('created_entity_ids')->nullable();
            $table->timestampsTz();
            $table->unique(['batch_id', 'row_number']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 30);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();   // optional unless the DPO requires it
            $table->string('email');
            $table->string('contact_number', 20)->nullable();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('cohort', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('compliance_state', 20)->default('not_monitored'); // engine-maintained
            $table->timestampTz('compliance_evaluated_at')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'compliance_state']);
            $table->index(['last_name', 'first_name']);
        });
        // BR-001: student number unique across ALL students, never reused (case-insensitive).
        DB::statement('CREATE UNIQUE INDEX students_student_number_unique ON students (lower(student_number))');
        // FR-011: unique email among active students.
        DB::statement("CREATE UNIQUE INDEX students_active_email_unique ON students (lower(email)) WHERE status NOT IN ('deactivated','withdrawn','graduated')");
        DB::statement("ALTER TABLE students ADD CONSTRAINT students_status_check CHECK (status IN ('active','on_leave','graduated','withdrawn','deactivated'))");
        DB::statement("ALTER TABLE students ADD CONSTRAINT students_compliance_check CHECK (compliance_state IN ('compliant','at_risk','non_compliant','data_issue','not_monitored'))");

        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_type_id')->constrained()->restrictOnDelete();
            $table->string('license_number', 60)->nullable();     // current number (EC-16)
            $table->unsignedBigInteger('current_period_id')->nullable(); // FK added below (circular)
            $table->string('current_status', 20)->default('incomplete_data'); // cache; engine only (DB-05 / BR-016)
            $table->timestampTz('status_evaluated_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);     // optimistic locking
            $table->timestampsTz();

            $table->index('current_status');
        });
        // BR-015: at most one current credential per type per student.
        DB::statement('CREATE UNIQUE INDEX credentials_student_type_current_unique ON credentials (student_id, credential_type_id) WHERE archived_at IS NULL');
        // BR-014: the same license number cannot belong to two credentials of the same type.
        DB::statement('CREATE UNIQUE INDEX credentials_type_number_unique ON credentials (credential_type_id, upper(license_number)) WHERE license_number IS NOT NULL');
        DB::statement("ALTER TABLE credentials ADD CONSTRAINT credentials_status_check CHECK (current_status IN ('active','expiring_soon','expired','incomplete_data','not_yet_valid'))");

        Schema::create('credential_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->string('license_number', 60)->nullable();   // number as held during this period
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('source', 20);
            $table->unsignedBigInteger('renewal_case_id')->nullable(); // FK added with renewal_cases
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('credential_periods')->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('expiry_date');
        });
        DB::statement('ALTER TABLE credential_periods ADD CONSTRAINT credential_periods_dates_check CHECK (expiry_date IS NULL OR issue_date IS NULL OR expiry_date > issue_date)'); // BR-011
        DB::statement("ALTER TABLE credential_periods ADD CONSTRAINT credential_periods_source_check CHECK (source IN ('manual','import','renewal'))");
        DB::statement('CREATE UNIQUE INDEX credential_periods_one_current ON credential_periods (credential_id) WHERE superseded_at IS NULL');

        Schema::table('credentials', function (Blueprint $table) {
            $table->foreign('current_period_id')->references('id')->on('credential_periods')->restrictOnDelete();
        });

        Schema::create('status_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->string('override_status', 20);
            $table->text('reason');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE status_overrides ADD CONSTRAINT status_overrides_status_check CHECK (override_status IN ('active','expiring_soon','expired','incomplete_data','not_yet_valid'))");
        DB::statement('CREATE UNIQUE INDEX status_overrides_one_open ON status_overrides (credential_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('status_overrides');
        Schema::table('credentials', fn (Blueprint $t) => $t->dropForeign(['current_period_id']));
        Schema::dropIfExists('credential_periods');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('students');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
