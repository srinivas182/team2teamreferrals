<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Referral forms, pipeline stages and (Sprint 8b) agreements. Families give defaults in code;
        // an industry (and later a group) can override. Every save is a version; one is active.
        Schema::create('industry_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12)->comment('form, stages or agreement');
            $table->string('scope_type', 10)->comment('industry or group');
            $table->unsignedBigInteger('scope_id');
            $table->unsignedInteger('version');
            $table->json('body');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'scope_type', 'scope_id', 'version']);
            $table->index(['kind', 'scope_type', 'scope_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_templates');
    }
};
