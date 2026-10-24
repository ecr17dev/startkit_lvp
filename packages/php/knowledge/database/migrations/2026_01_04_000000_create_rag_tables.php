<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Control plane of the knowledge module (always in the application
     * database): connections, embedding profiles, collections, sources,
     * documents with their versions, index versions and durable jobs.
     *
     * The data plane (chunks, full text and vectors) is provisioned on the
     * RAG connection by `Starter\Knowledge\Store\Provisioner` only after the
     * diagnostics pass, so a disabled RAG never needs pgvector.
     */
    public function up(): void
    {
        Schema::create('rag_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('label', 120);
            $table->string('kind', 16);
            $table->jsonb('config')->nullable();
            $table->text('credentials')->nullable();
            $table->string('status', 16)->default('pending');
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('diagnostics')->nullable();
            $table->string('pgvector_version', 16)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX rag_connections_one_default ON rag_connections (is_default) WHERE is_default');

        Schema::create('rag_embedding_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('rag_connections')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('model', 160);
            $table->unsignedInteger('dimensions');
            $table->string('metric', 16)->default('cosine');
            $table->boolean('normalize')->default(true);
            $table->unsignedInteger('max_input_tokens')->default(8000);
            $table->unsignedSmallInteger('batch_size')->default(64);
            $table->jsonb('storage')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'name']);
        });

        Schema::create('rag_collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('connection_id')->constrained('rag_connections')->restrictOnDelete();
            $table->foreignId('embedding_profile_id')->constrained('rag_embedding_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('active_index_version_id')->nullable();
            $table->string('language', 16)->default('simple');
            $table->jsonb('chunking');
            $table->jsonb('search');
            $table->jsonb('acl');
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rag_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('rag_collections')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 160);
            $table->jsonb('config');
            $table->jsonb('acl')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('schedule', 16)->nullable();
            $table->timestamp('next_sync_at')->nullable()->index();
            $table->timestamp('last_sync_at')->nullable();
            $table->uuid('last_job_id')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('rag_collections')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('rag_sources')->cascadeOnDelete();
            $table->string('external_id', 2048);
            $table->string('external_hash', 64);
            $table->string('title', 300)->nullable();
            $table->string('uri', 2048)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('latest_version_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('token_count')->default(0);
            $table->jsonb('acl')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();

            // The external id can be a long URL: uniqueness uses its hash...
            $table->unique(['source_id', 'external_hash']);
            $table->index(['collection_id', 'status']);
        });

        Schema::create('rag_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('content_hash', 64);
            $table->string('parser', 24);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->longText('content');
            $table->jsonb('pages')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'number']);
        });

        Schema::create('rag_index_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('rag_collections')->cascadeOnDelete();
            $table->foreignId('embedding_profile_id')->constrained('rag_embedding_profiles')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->string('reason', 24)->default('initial');
            $table->jsonb('chunking');
            $table->unsignedInteger('documents')->default(0);
            $table->unsignedInteger('chunks')->default(0);
            $table->unsignedBigInteger('tokens')->default(0);
            $table->decimal('cost', 20, 10)->default(0);
            $table->boolean('cost_known')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'number']);
        });

        DB::statement("CREATE UNIQUE INDEX rag_index_versions_one_active ON rag_index_versions (collection_id) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX rag_index_versions_one_building ON rag_index_versions (collection_id) WHERE status = 'building'");

        Schema::table('rag_collections', function (Blueprint $table) {
            $table->foreign('active_index_version_id')->references('id')->on('rag_index_versions')->nullOnDelete();
        });

        Schema::table('rag_documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('rag_document_versions')->nullOnDelete();
            $table->foreign('latest_version_id')->references('id')->on('rag_document_versions')->nullOnDelete();
        });

        Schema::create('rag_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->foreignId('collection_id')->nullable()->constrained('rag_collections')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('rag_sources')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('rag_documents')->nullOnDelete();
            $table->foreignId('index_version_id')->nullable()->constrained('rag_index_versions')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 16)->index();
            $table->string('stage', 16)->nullable();
            $table->unsignedInteger('progress_total')->nullable();
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('payload')->nullable();
            $table->jsonb('units')->nullable();
            $table->jsonb('timings')->nullable();
            $table->jsonb('estimate')->nullable();
            $table->decimal('cost', 20, 10)->default(0);
            $table->boolean('cost_known')->default(true);
            $table->decimal('reserved', 20, 10)->default(0);
            $table->decimal('max_cost', 20, 10)->nullable();
            $table->text('error')->nullable();
            $table->string('error_type', 40)->nullable();
            $table->string('trigger', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['collection_id', 'created_at']);
            $table->index(['source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_jobs');

        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
            $table->dropForeign(['latest_version_id']);
        });

        Schema::table('rag_collections', function (Blueprint $table) {
            $table->dropForeign(['active_index_version_id']);
        });

        Schema::dropIfExists('rag_index_versions');
        Schema::dropIfExists('rag_document_versions');
        Schema::dropIfExists('rag_documents');
        Schema::dropIfExists('rag_sources');
        Schema::dropIfExists('rag_collections');
        Schema::dropIfExists('rag_embedding_profiles');
        Schema::dropIfExists('rag_connections');
    }
};
