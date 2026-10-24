<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail automations (welcome, inactivity, scheduled sends to a role, system
 * events) and the log of every email they send. A delivery key makes each
 * send idempotent: the same automation never mails the same person twice
 * for the same occurrence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_automations')) {
            Schema::create('mail_automations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('trigger', 30);
                $table->jsonb('settings');
                $table->string('template_key', 120);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['trigger', 'is_active']);
                $table->index('template_key');
            });
        }

        if (! Schema::hasTable('mail_deliveries')) {
            Schema::create('mail_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('automation_id')->nullable()->constrained('mail_automations')->nullOnDelete();
                $table->string('template_key', 120);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email');
                $table->string('subject')->nullable();
                $table->jsonb('variables')->nullable();
                $table->string('status', 12)->default('pending');
                $table->text('error')->nullable();
                $table->string('delivery_key', 191)->nullable()->unique();
                $table->timestamp('send_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['automation_id', 'created_at']);
                $table->index(['status', 'send_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
        Schema::dropIfExists('mail_automations');
    }
};
