<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $t) {
            $t->json('specialties')->nullable()->after('service_area');
            $t->json('languages')->nullable()->after('specialties');
        });
        // Badges held now (revoked_at set when no longer earned at a monthly re-check); history is kept.
        Schema::create('member_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('badge', 24);
            $table->foreignId('group_id')->nullable()->comment('For per-network badges')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');
            $table->timestamp('revoked_at')->nullable();
            $table->index(['team_id', 'revoked_at']);
            $table->index(['badge', 'revoked_at']);
        });
        // Monthly leaderboards are frozen on the 1st so they never change afterwards.
        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->json('rows');
            $table->timestamp('created_at');
            $table->unique(['group_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
        Schema::dropIfExists('member_badges');
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn(['specialties', 'languages']));
    }
};
