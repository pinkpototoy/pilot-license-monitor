<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.5 — schema in place now; the engine arrives in Phase 4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->string('channel', 10);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['code', 'channel', 'version']);
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->smallInteger('offset_days');                 // negative = before expiry, 0 = on expiry day
            $table->string('recipients', 10)->default('student'); // student / staff / both
            $table->json('channels');
            $table->string('template_code', 50);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_recipients_check CHECK (recipients IN ('student','staff','both'))");

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('notification_rules')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->foreignId('period_id')->nullable()->constrained('credential_periods')->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('dedupe_key')->unique();           // BR-030
            $table->string('subject')->nullable();
            $table->text('body_rendered');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'read_at']);
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('pending','sent','failed','cancelled','suppressed'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('email','in_app','sms'))");

        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->string('provider', 40);
            $table->string('provider_message_id')->nullable();
            $table->string('result', 20);
            $table->text('error_message')->nullable();
            $table->timestampTz('attempted_at')->useCurrent();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('channel', 10);
            $table->boolean('enabled')->default(true);
            $table->primary(['user_id', 'event_type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_attempts');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_templates');
    }
};
