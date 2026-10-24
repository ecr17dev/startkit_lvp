<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budgets by period and scope with atomic reservations, plus the
     * exchange rates used only to display costs in other currencies.
     */
    public function up(): void
    {
        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // global, user, role, feature or connection...
            $table->string('scope', 20)->default('global');
            $table->string('scope_value', 120)->nullable();
            $table->string('period', 10)->default('monthly');
            $table->decimal('limit_amount', 20, 6);
            $table->char('currency', 3)->default('USD');
            $table->jsonb('alert_thresholds')->nullable();
            $table->boolean('hard_limit')->default(true);
            $table->boolean('block_unknown_cost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'scope_value', 'is_active']);
        });

        Schema::create('ai_budget_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('ai_budgets')->cascadeOnDelete();
            $table->string('period_key', 10);
            $table->decimal('spent', 20, 10)->default(0);
            $table->decimal('reserved', 20, 10)->default(0);
            $table->unsignedInteger('unknown_cost_requests')->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'period_key']);
        });

        Schema::create('ai_budget_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_period_id')->constrained('ai_budget_periods')->cascadeOnDelete();
            $table->foreignUuid('ai_request_id')->constrained('ai_requests')->cascadeOnDelete();
            $table->decimal('amount', 20, 10);
            $table->decimal('settled_amount', 20, 10)->nullable();
            $table->string('status', 20);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['budget_period_id', 'ai_request_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('ai_budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_period_id')->constrained('ai_budget_periods')->cascadeOnDelete();
            $table->unsignedSmallInteger('threshold');
            $table->decimal('spent', 20, 10);
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->unique(['budget_period_id', 'threshold']);
        });

        Schema::create('ai_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3)->default('USD');
            $table->char('quote', 3);
            $table->decimal('rate', 20, 8);
            $table->date('rate_date');
            $table->string('source', 60);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['base', 'quote', 'rate_date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_exchange_rates');
        Schema::dropIfExists('ai_budget_alerts');
        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_budget_periods');
        Schema::dropIfExists('ai_budgets');
    }
};
