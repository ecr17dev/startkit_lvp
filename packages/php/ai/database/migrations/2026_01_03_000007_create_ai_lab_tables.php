<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lab: the same prompts against several models, under a maximum cost
     * and concurrency. Each result keeps output, error, latency, time to
     * first token, tokens and the ledger request with its cost.
     */
    public function up(): void
    {
        Schema::create('ai_lab_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->jsonb('prompts');
            $table->text('system_prompt')->nullable();
            $table->jsonb('model_ids');
            $table->unsignedInteger('max_tokens')->nullable();
            $table->decimal('max_cost', 20, 10);
            $table->unsignedSmallInteger('max_concurrency')->default(2);
            $table->decimal('spent', 20, 10)->default(0);
            $table->decimal('reserved', 20, 10)->default(0);
            $table->string('status', 16)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_lab_runs')->cascadeOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedSmallInteger('prompt_index');
            $table->string('status', 16)->index();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('ttft_ms')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 20, 10)->nullable();
            $table->decimal('cost', 20, 10)->nullable();
            $table->string('cost_status', 16)->nullable();
            $table->foreignUuid('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'model_id', 'prompt_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_lab_results');
        Schema::dropIfExists('ai_lab_runs');
    }
};
