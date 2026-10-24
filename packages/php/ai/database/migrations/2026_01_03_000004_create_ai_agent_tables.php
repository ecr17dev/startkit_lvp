<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable agents with versioned settings: draft, published and
     * archived versions. Conversations record the version through the
     * ledger (ai_requests.agent_version_id).
     */
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_agent_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 16)->index();
            $table->jsonb('config');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'number']);
        });

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('ai_agent_versions')->nullOnDelete();
        });

        // One editable draft per agent and a single default agent...
        DB::statement("CREATE UNIQUE INDEX ai_agent_versions_one_draft ON ai_agent_versions (agent_id) WHERE status = 'draft'");
        DB::statement('CREATE UNIQUE INDEX ai_agents_one_default ON ai_agents (is_default) WHERE is_default');

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->foreign('agent_version_id')->references('id')->on('ai_agent_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropForeign(['agent_version_id']);
        });

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('ai_agent_versions');
        Schema::dropIfExists('ai_agents');
    }
};
