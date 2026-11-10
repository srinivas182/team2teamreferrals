<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far each team has read a referral's messages: "Seen" receipts and unread counts.
        Schema::create('referral_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('read_at');
            $table->unique(['referral_id', 'team_id']);
        });
        // A person can mute a referral's messages; deadline reminders still come through.
        Schema::create('referral_mutes', function (Blueprint $table) {
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->primary(['referral_id', 'user_id']);
        });
        Schema::table('teams', fn (Blueprint $t) => $t->json('quick_replies')->nullable()->after('founded_year'));
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn('quick_replies'));
        Schema::dropIfExists('referral_mutes');
        Schema::dropIfExists('referral_reads');
    }
};
