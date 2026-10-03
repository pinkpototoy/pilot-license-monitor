<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SRS 22.2 / 22.3 configuration tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('credential_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('issuing_authority')->nullable();
            $table->boolean('expires')->default(true);
            $table->unsignedSmallInteger('default_validity_months')->nullable();
            $table->unsignedSmallInteger('max_validity_months')->nullable();   // BR-013
            $table->unsignedSmallInteger('expiring_soon_days')->default(30);
            $table->string('number_pattern')->nullable();                      // FR-022 (regex)
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('program_required_credentials', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_type_id')->constrained()->restrictOnDelete();
            $table->primary(['program_id', 'credential_type_id']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('accepted_mime_types');
            $table->unsignedSmallInteger('max_size_mb')->default(10);
            $table->timestampsTz();
        });

        Schema::create('credential_type_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->boolean('mandatory')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['credential_type_id', 'document_type_id']);
        });

        DB::statement('ALTER TABLE credential_types ADD CONSTRAINT credential_types_soon_days_check CHECK (expiring_soon_days BETWEEN 1 AND 365)');
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_type_requirements');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('program_required_credentials');
        Schema::dropIfExists('credential_types');
        Schema::dropIfExists('programs');
    }
};
