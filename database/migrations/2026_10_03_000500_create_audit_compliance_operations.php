<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.6 — audit, compliance history, operations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);              // 'system' for scheduled jobs
            $table->string('action', 60);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable(); // no FK on purpose (survives anonymization)
            $table->jsonb('changes')->nullable();
            $table->text('remarks')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->index('occurred_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('student_id');
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index('action');
        });

        // BR-041 / NFR-009: append-only, enforced in the database for every role.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_changes() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (BR-041)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_changes();
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_changes();
            CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_changes();
        SQL);

        Schema::create('compliance_snapshots', function (Blueprint $table) {
            $table->date('snapshot_date');
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->string('validity_status', 20);
            $table->string('compliance_state', 20);
            $table->string('case_status', 30)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['snapshot_date', 'credential_id']);
            $table->index(['snapshot_date', 'compliance_state']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('report_code', 20);
            $table->jsonb('filters')->nullable();
            $table->string('format', 5);
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_key')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });

        // NFR-018: proof that scheduled jobs ran (addition to the SRS table list).
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 60);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('items_processed')->default(0);
            $table->jsonb('summary')->nullable();
            $table->text('error')->nullable();
            $table->index(['job_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('compliance_snapshots');
        DB::unprepared('DROP TABLE IF EXISTS audit_logs CASCADE; DROP FUNCTION IF EXISTS audit_logs_block_changes();');
    }
};
