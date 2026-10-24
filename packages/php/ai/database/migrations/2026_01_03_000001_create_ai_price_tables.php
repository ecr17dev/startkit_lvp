<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Price catalog. Prices are versioned: a change closes the current row
     * (effective_to) and opens a new one, so the history never changes.
     */
    public function up(): void
    {
        Schema::create('ai_price_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            // direct (provider list price), gateway, custom (agreement) or manual...
            $table->string('kind', 20);
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->foreignId('price_source_id')->constrained('ai_price_sources')->restrictOnDelete();
            $table->string('component', 30);
            $table->string('unit', 30);
            $table->decimal('amount', 20, 10);
            $table->char('currency', 3)->default('USD');
            $table->string('tier', 20)->default('standard');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['model_id', 'component', 'tier', 'effective_to']);
        });

        Schema::create('ai_price_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_source_id')->constrained('ai_price_sources')->cascadeOnDelete();
            $table->string('status', 20);
            $table->jsonb('stats')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_price_sync_runs');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_price_sources');
    }
};
