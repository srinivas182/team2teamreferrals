<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a member wants referral status replies: WhatsApp, SMS or nowhere (their choice, off by default).
        Schema::table('users', fn (Blueprint $t) => $t->string('chat_channel', 10)->default('none')->after('phone_verified_at'));
        // Each status reply and bot reply we pay for, to measure spend against the monthly cap.
        Schema::create('chat_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10);
            $table->string('kind', 10)->comment('status, template, reply');
            $table->string('type', 40)->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->string('outcome', 12)->comment('sent, skipped');
            $table->string('reason', 80)->nullable();
            $table->timestamp('created_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_deliveries');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('chat_channel'));
    }
};
