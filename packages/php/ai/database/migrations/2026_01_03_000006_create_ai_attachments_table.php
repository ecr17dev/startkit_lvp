<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files attached to chat messages, kept on a private disk for the
     * retention period. They are never added to a knowledge collection
     * automatically.
     */
    public function up(): void
    {
        Schema::create('ai_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('disk', 40);
            $table->string('path')->unique();
            $table->string('name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('kind', 16);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachments');
    }
};
