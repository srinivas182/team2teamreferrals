<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What members did with each suggestion: used, dismissed (with an optional reason) or snoozed.
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('next_action, draft, match, insight');
            $table->string('key', 40);
            $table->string('status', 10)->comment('used, dismissed, snoozed');
            $table->string('reason', 40)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'referral_id', 'key']);
        });
        // The receiving team's close-likelihood score, kept to measure whether suggestions help.
        Schema::create('referral_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('reasons');
            $table->timestamp('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_scores');
        Schema::dropIfExists('ai_suggestions');
    }
};
