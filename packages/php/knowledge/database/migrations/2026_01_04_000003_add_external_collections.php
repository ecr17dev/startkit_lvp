<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collections of a retrieval API connection point to a collection of
     * the remote service (external_id) and have no embedding profile: the
     * service embeds, indexes and ranks.
     */
    public function up(): void
    {
        Schema::table('rag_collections', function (Blueprint $table) {
            $table->unsignedBigInteger('embedding_profile_id')->nullable()->change();

            if (! Schema::hasColumn('rag_collections', 'external_id')) {
                $table->string('external_id', 200)->nullable()->after('connection_id');
                $table->unique(['connection_id', 'external_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rag_collections', function (Blueprint $table) {
            $table->dropUnique(['connection_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
