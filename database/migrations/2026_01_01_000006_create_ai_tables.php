<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('driver', 50);
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->text('options')->nullable();
            $table->string('default_model')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->string('label')->nullable();
            $table->decimal('input_price', 12, 6)->nullable();
            $table->decimal('output_price', 12, 6)->nullable();
            $table->decimal('cache_read_price', 12, 6)->nullable();
            $table->decimal('cache_write_price', 12, 6)->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['ai_provider_id', 'model']);
        });

        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->longText('content');
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invocation_id')->index();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('agent');
            $table->string('source', 20)->default('web');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('cost', 14, 8)->default(0);
            $table->boolean('priced')->default(false);
            $table->timestamps();

            $table->index(['created_at', 'provider', 'model']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_prompts');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
