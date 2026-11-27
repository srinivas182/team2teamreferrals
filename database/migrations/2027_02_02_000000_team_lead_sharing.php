<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a team shares new referrals: open to everyone (first to accept gets it), take turns, or the leader assigns.
 * How long members have to accept (null: a quarter of the network's response time). Take turns remembers whose
 * turn was last, and each referral remembers who it is offered to now and until when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('lead_sharing', 10)->default('everyone')->comment('everyone, turns, leader');
            $table->unsignedSmallInteger('member_accept_minutes')->nullable()->comment('null: a quarter of the network response time');
            $table->foreignId('turn_last_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('offered_user_id')->nullable()->after('receiver_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('offered_until')->nullable()->after('offered_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_user_id');
            $table->dropColumn('offered_until');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('turn_last_user_id');
            $table->dropColumn(['lead_sharing', 'member_accept_minutes']);
        });
    }
};
