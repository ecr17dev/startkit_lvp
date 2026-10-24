<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage ledger. A request is a logical execution; attempts are the real
     * calls (retries, failovers). Costs are decimals: null means unknown,
     * never free. Cost snapshots keep the prices applied to each estimate.
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('agent', 120)->nullable();
            $table->unsignedBigInteger('agent_version_id')->nullable();
            $table->string('conversation_id', 64)->nullable()->index();
            $table->string('feature', 30);
            $table->foreignId('connection_id')->nullable()->constrained('ai_provider_connections')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('provider', 64)->nullable();
            $table->string('model')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->jsonb('units')->nullable();
            $table->decimal('estimated_cost', 20, 10)->nullable();
            $table->decimal('cost', 20, 10)->nullable();
            $table->decimal('reconciled_cost', 20, 10)->nullable();
            $table->string('cost_status', 20);
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('time_to_first_token_ms')->nullable();
            $table->string('error_type', 30)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index(['connection_id', 'created_at']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('ai_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_request_id')->constrained('ai_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('invocation_id', 64)->nullable()->index();
            $table->foreignId('connection_id')->nullable()->constrained('ai_provider_connections')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('provider', 64)->nullable();
            $table->string('model')->nullable();
            $table->string('status', 20);
            $table->boolean('billable')->default(true);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->jsonb('units')->nullable();
            $table->decimal('cost', 20, 10)->nullable();
            $table->string('cost_status', 20);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_type', 30)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_request_id', 'number']);
        });

        Schema::create('ai_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_request_id')->constrained('ai_requests')->cascadeOnDelete();
            $table->foreignId('ai_attempt_id')->nullable()->constrained('ai_attempts')->cascadeOnDelete();
            // estimate (before the call), calculated (from reported usage) or reconciled (provider invoice)...
            $table->string('kind', 20);
            $table->decimal('amount', 20, 10)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->jsonb('prices')->nullable();
            $table->jsonb('units')->nullable();
            $table->jsonb('missing')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ai_request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cost_snapshots');
        Schema::dropIfExists('ai_attempts');
        Schema::dropIfExists('ai_requests');
    }
};
