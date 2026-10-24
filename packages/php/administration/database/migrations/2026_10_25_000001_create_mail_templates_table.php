<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates edited in the panel. A system template (login code,
 * invitation...) has a row only once it is customized; custom templates
 * (key custom.*) are created by administrators for automations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_templates')) {
            return;
        }

        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->string('locale', 8);
            $table->string('name')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('format', 10)->default('markdown');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'locale']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
