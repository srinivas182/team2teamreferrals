<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who in the receiving team accepted a referral. Team members see only the referrals they sent or accepted (plus
 * offers still open to the team); leaders and assistants see all. Referrals accepted before this belong to the leader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('receiver_user_id')->nullable()->after('receiver_team_id')->constrained('users')->nullOnDelete();
            $table->index(['receiver_team_id', 'receiver_user_id']);
        });
        foreach (DB::table('referrals')->join('teams', 'teams.id', '=', 'referrals.receiver_team_id')->where('referrals.status', 'accepted')->get(['referrals.id', 'teams.leader_id']) as $r) {
            DB::table('referrals')->where('id', $r->id)->update(['receiver_user_id' => $r->leader_id]);
        }
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['receiver_team_id', 'receiver_user_id']);
            $table->dropConstrainedForeignId('receiver_user_id');
        });
    }
};
