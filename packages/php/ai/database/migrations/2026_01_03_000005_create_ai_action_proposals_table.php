<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changes prepared by agent tools that wait for the user's confirmation.
     * A proposal is bound to the tool call, the actor, the arguments and the
     * state of the resource; it expires and can be used only once.
     */
    public function up(): void
    {
        Schema::create('ai_action_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_version_id')->nullable()->constrained('ai_agent_versions')->nullOnDelete();
            $table->foreignUuid('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('tool', 64);
            $table->string('tool_call_id', 191);
            $table->string('kind', 16);
            $table->string('resource_type', 40)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('resource_version', 64)->nullable();
            $table->jsonb('arguments');
            $table->string('arguments_hash', 64);
            $table->jsonb('preview');
            $table->string('status', 16)->index();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tool_call_id']);
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_proposals');
    }
};
