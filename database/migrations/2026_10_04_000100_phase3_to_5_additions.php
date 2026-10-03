<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Schema additions for Phases 3–5 (renewals, notifications, reports, import). */
return new class extends Migration
{
    public function up(): void
    {
        // Phase 4: students without an account still get email reminders, so the recipient
        // can be an address rather than a user.
        Schema::table('notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->change();
            $t->string('recipient_email')->nullable()->after('user_id');
            $t->foreignId('case_id')->nullable()->after('period_id')->constrained('renewal_cases')->restrictOnDelete();
            $t->text('last_error')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->index(['status', 'next_attempt_at']);
        });
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_recipient_check CHECK (user_id IS NOT NULL OR recipient_email IS NOT NULL)');

        // BR-033: never send to an address known to be invalid (hard bounce, marked by staff).
        Schema::table('students', fn (Blueprint $t) => $t->timestampTz('email_invalid_at')->nullable());
        Schema::table('users', fn (Blueprint $t) => $t->timestampTz('email_invalid_at')->nullable());

        // Phase 3: idle reminders and the BR-027 auto-cancel warning.
        Schema::table('renewal_cases', function (Blueprint $t) {
            $t->timestampTz('last_activity_at')->nullable();
            $t->timestampTz('draft_warning_sent_at')->nullable();
        });

        // Phase 5: import traceability and rollback.
        Schema::table('import_batches', function (Blueprint $t) {
            $t->string('date_convention', 10)->default('iso');
            $t->string('original_file_key')->nullable();
            $t->timestampTz('rolled_back_at')->nullable();
            $t->foreignId('rolled_back_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('rolled_back_by'));
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn(['date_convention', 'original_file_key', 'rolled_back_at']));
        Schema::table('renewal_cases', fn (Blueprint $t) => $t->dropColumn(['last_activity_at', 'draft_warning_sent_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('email_invalid_at'));
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn('email_invalid_at'));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_recipient_check');
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropConstrainedForeignId('case_id');
            $t->dropColumn(['recipient_email', 'last_error', 'next_attempt_at']);
        });
    }
};
