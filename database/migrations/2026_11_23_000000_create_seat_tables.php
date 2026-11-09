<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minimal teams; profiles, members and licenses arrive with onboarding (Sprint 12).
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 160);
            $table->foreignId('leader_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // One seat per territory per group: a primary team, at most one backup, and a waitlist.
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('territory_type', 8)->comment('city, county, metro, state');
            $table->unsignedBigInteger('territory_id');
            $table->string('territory_name', 160);
            $table->string('state_code', 2)->nullable();
            $table->string('status', 10)->default('open')->comment('open, trial, active, grace, inactive');
            $table->foreignId('primary_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('backup_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('founding')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('inactive_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'territory_type', 'territory_id']);
            $table->index(['status', 'grace_started_at']);
        });

        Schema::create('seat_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->string('license_number', 60)->nullable();
            $table->string('status', 10)->default('pending')->comment('pending, approved, declined, withdrawn');
            $table->string('outcome', 12)->nullable()->comment('primary, backup, waitlist');
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status']);
        });

        Schema::create('seat_waitlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0)->comment('Ranking: profile strength now, trust score from Sprint 34');
            $table->timestamp('joined_at');
            $table->unique(['seat_id', 'team_id']);
        });

        // A seat offered to one team at a time, with a claim window; unclaimed offers pass on automatically.
        Schema::create('seat_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('pending')->comment('pending, accepted, declined, expired');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        // Seat history: every holder and change, for disputes and seat challenges.
        Schema::create('seat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 24);
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['seat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['seat_events', 'seat_offers', 'seat_waitlist', 'seat_applications', 'seats', 'teams'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
