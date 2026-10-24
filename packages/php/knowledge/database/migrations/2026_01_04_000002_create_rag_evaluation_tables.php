<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quality of retrieval: question sets with the documents that should be
     * found, runs comparing settings (recall@k, MRR, hit rate, latency,
     * cost), human judgements of retrieved passages and feedback on
     * grounded answers.
     */
    public function up(): void
    {
        Schema::create('rag_evaluation_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->jsonb('collection_ids');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rag_evaluation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('rag_evaluation_sets')->cascadeOnDelete();
            $table->text('question');
            $table->jsonb('expected_document_ids');
            $table->text('expected_answer')->nullable();
            $table->timestamps();
        });

        Schema::create('rag_evaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('set_id')->constrained('rag_evaluation_sets')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->jsonb('settings');
            $table->string('status', 16)->index();
            $table->jsonb('metrics')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rag_evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('rag_evaluation_runs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('rag_evaluation_items')->cascadeOnDelete();
            $table->foreignUuid('retrieval_run_id')->nullable()->constrained('rag_retrieval_runs')->nullOnDelete();
            $table->jsonb('retrieved_document_ids');
            $table->unsignedSmallInteger('first_relevant_rank')->nullable();
            $table->double('recall');
            $table->double('reciprocal_rank');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->decimal('cost', 20, 10)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'item_id']);
        });

        Schema::table('rag_retrieval_runs', function (Blueprint $table) {
            $table->smallInteger('feedback')->nullable();
            $table->text('feedback_note')->nullable();
            $table->timestamp('feedback_at')->nullable();
        });

        Schema::table('rag_retrieval_hits', function (Blueprint $table) {
            $table->smallInteger('judgement')->nullable();
            $table->foreignId('judged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('judged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rag_retrieval_hits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('judged_by');
            $table->dropColumn(['judgement', 'judged_at']);
        });

        Schema::table('rag_retrieval_runs', function (Blueprint $table) {
            $table->dropColumn(['feedback', 'feedback_note', 'feedback_at']);
        });

        Schema::dropIfExists('rag_evaluation_results');
        Schema::dropIfExists('rag_evaluation_runs');
        Schema::dropIfExists('rag_evaluation_items');
        Schema::dropIfExists('rag_evaluation_sets');
    }
};
