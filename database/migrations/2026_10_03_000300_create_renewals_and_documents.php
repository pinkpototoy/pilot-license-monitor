<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.4 — schema in place now; workflow logic arrives in Phase 3. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE renewal_cases ADD CONSTRAINT renewal_cases_status_check CHECK (status IN ('draft','submitted','under_verification','needs_correction','resubmitted','approved','completed','cancelled'))");
        // BR-020: one open renewal case per credential.
        DB::statement("CREATE UNIQUE INDEX renewal_cases_one_open ON renewal_cases (credential_id) WHERE status NOT IN ('completed','cancelled')");

        Schema::table('credential_periods', function (Blueprint $table) {
            $table->foreign('renewal_case_id')->references('id')->on('renewal_cases')->restrictOnDelete();
        });

        Schema::create('renewal_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('renewal_cases')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('rejection_reasons', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('label');
            $table->boolean('requires_note')->default(false);
            $table->boolean('active')->default(true);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('renewal_cases')->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('credential_type_requirements')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->string('original_filename');
            $table->string('storage_key')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('pending');
            $table->string('verification_status', 20)->default('pending');
            $table->string('rejection_reason_code', 30)->nullable();
            $table->foreign('rejection_reason_code')->references('code')->on('rejection_reasons')->restrictOnDelete();
            $table->text('rejection_note')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at')->useCurrent();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->index(['case_id', 'verification_status']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_scan_check CHECK (scan_status IN ('pending','clean','infected','error'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_verification_check CHECK (verification_status IN ('pending','approved','rejected','superseded'))");
        // BR-031 enforced in the database too: no one verifies their own upload.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_separation_of_duties CHECK (verified_by IS NULL OR verified_by <> uploaded_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('rejection_reasons');
        Schema::dropIfExists('renewal_case_events');
        Schema::table('credential_periods', fn (Blueprint $t) => $t->dropForeign(['renewal_case_id']));
        Schema::dropIfExists('renewal_cases');
    }
};
