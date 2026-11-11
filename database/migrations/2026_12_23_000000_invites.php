<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // One invite link per team (/join/{code}).
        Schema::table('teams', fn (Blueprint $t) => $t->string('invite_code', 12)->nullable()->unique()->after('slug'));
        foreach (DB::table('teams')->pluck('id') as $id) {
            DB::table('teams')->where('id', $id)->update(['invite_code' => strtolower(Str::random(8))]);
        }
        // Who joined through whose link, and what the inviter received.
        Schema::create('invite_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('joined')->comment('joined, rewarded, badge, capped');
            $table->unsignedInteger('reward_cents')->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();
            $table->index(['inviter_team_id', 'status']);
        });
        // Group owners: one free month per N new paid seats; each seat counts once.
        Schema::create('owner_reward_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_reward_seats');
        Schema::dropIfExists('invite_signups');
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn('invite_code'));
    }
};
