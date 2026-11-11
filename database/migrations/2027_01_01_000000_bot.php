<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The network's own form answers collected for a draft (e.g. by the chat bot).
        Schema::table('referral_drafts', fn (Blueprint $t) => $t->json('answers')->nullable()->after('notes'));
        // A member's conversation with the bot on WhatsApp or SMS. Ends when sent, cancelled or after 30 quiet minutes.
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10)->comment('whatsapp or sms');
            $table->string('phone', 20);
            $table->string('state', 12)->comment('collecting, confirming, done, cancelled');
            $table->string('asking', 60)->nullable()->comment('The question waiting for an answer');
            $table->foreignId('draft_id')->nullable()->constrained('referral_drafts')->nullOnDelete();
            $table->timestamp('last_inbound_at');
            $table->timestamps();
            $table->index(['user_id', 'state']);
        });
        // The conversation itself, deleted with the draft (14 days at most).
        Schema::create('bot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('bot_sessions')->cascadeOnDelete();
            $table->string('direction', 3)->comment('in or out');
            $table->text('body')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_messages');
        Schema::dropIfExists('bot_sessions');
        Schema::table('referral_drafts', fn (Blueprint $t) => $t->dropColumn('answers'));
    }
};
