<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrieval traces: one run per search (chat, tool, lab or API) with its
     * settings, latency, cost and outcome, and its ranked hits. Hits keep
     * ids, scores and location, never a copy of the restricted content.
     */
    public function up(): void
    {
        Schema::create('rag_retrieval_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_version_id')->nullable()->constrained('ai_agent_versions')->nullOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('message_id', 36)->nullable()->index();
            $table->foreignUuid('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->foreignUuid('chat_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->string('surface', 16);
            $table->text('query');
            $table->string('method', 16);
            $table->jsonb('settings');
            $table->jsonb('collection_ids');
            $table->unsignedSmallInteger('hits_count')->default(0);
            $table->unsignedInteger('context_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('embedding_ms')->nullable();
            $table->unsignedInteger('search_ms')->default(0);
            $table->decimal('cost', 20, 10)->nullable();
            $table->boolean('cost_known')->default(true);
            $table->boolean('used')->default(false);
            $table->string('outcome', 16)->nullable();
            $table->unsignedSmallInteger('citations_count')->default(0);
            $table->unsignedSmallInteger('invalid_citations')->default(0);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['surface', 'created_at']);
        });

        Schema::create('rag_retrieval_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('rag_retrieval_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedBigInteger('chunk_id');
            $table->foreignId('document_id')->nullable()->constrained('rag_documents')->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('rag_collections')->nullOnDelete();
            $table->double('score');
            $table->string('score_type', 24);
            $table->double('vector_score')->nullable();
            $table->double('text_score')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->boolean('cited')->default(false);
            $table->string('title', 300)->nullable();
            $table->jsonb('location')->nullable();

            $table->index(['run_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_retrieval_hits');
        Schema::dropIfExists('rag_retrieval_runs');
    }
};
