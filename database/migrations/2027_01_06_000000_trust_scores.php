<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recomputed nightly and shown on profiles, the directory and leaderboards.
        Schema::create('trust_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable()->comment('Null while "New"');
            $table->string('status', 6)->comment('new or scored');
            $table->json('parts');
            $table->timestamp('computed_at');
        });
        // One row per team per night, for trends.
        Schema::create('trust_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('score');
            $table->unique(['team_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_score_history');
        Schema::dropIfExists('trust_scores');
    }
};
