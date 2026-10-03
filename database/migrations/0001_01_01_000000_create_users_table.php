<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.1 Identity and access. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();          // BR-002
            $table->string('password');                  // Argon2id hash (NFR-005)
            $table->string('role', 20);
            $table->string('status', 20)->default('invited');
            $table->text('mfa_secret')->nullable();      // encrypted cast in the model
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_recovery_codes')->nullable(); // encrypted JSON of hashed codes
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->index(['role', 'status']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin','staff','student','viewer'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('invited','active','locked','deactivated'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // Server-side sessions (SRS "user_sessions"): Laravel's database session store,
        // so sessions can be listed and revoked per user.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email_attempted');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('success');
            $table->string('failure_reason', 40)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['email_attempted', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
