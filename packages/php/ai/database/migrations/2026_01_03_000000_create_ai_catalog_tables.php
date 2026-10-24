<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connections, model catalog and capabilities.
     *
     * Credentials are stored encrypted with the application key (outside the
     * database) or referenced from deployment secrets (secret_ref = env:NAME).
     */
    public function up(): void
    {
        Schema::create('ai_provider_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('label');
            $table->string('driver', 40);
            $table->string('base_url')->nullable();
            $table->text('credentials')->nullable();
            $table->string('secret_ref', 120)->nullable();
            $table->jsonb('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamp('credentials_rotated_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ai_provider_connections')->cascadeOnDelete();
            // Exact identifier sent to the provider...
            $table->string('model');
            $table->string('label')->nullable();
            // Who serves the model (e.g. "anthropic" behind a gateway)...
            $table->string('provider', 64)->nullable();
            $table->jsonb('functions')->nullable();
            $table->jsonb('input_modalities')->nullable();
            $table->jsonb('output_modalities')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->string('status', 20)->default('unknown');
            $table->string('source', 30)->default('manual');
            $table->string('source_url')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'model']);
            $table->index(['status', 'is_enabled']);
        });

        Schema::create('ai_model_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->string('capability', 40);
            $table->string('status', 20)->default('unknown');
            $table->string('source', 40)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['model_id', 'capability']);
            $table->index(['capability', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_capabilities');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_provider_connections');
    }
};
