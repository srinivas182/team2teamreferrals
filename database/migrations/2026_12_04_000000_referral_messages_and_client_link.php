<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Built-in messages between the two teams on a referral (kept with the referral's record).
        Schema::create('referral_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['referral_id', 'id']);
        });
        // The client's private status page: a random token, never indexed.
        Schema::table('referrals', fn (Blueprint $t) => $t->string('client_token', 64)->nullable()->unique()->after('ulid'));
    }

    public function down(): void
    {
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn('client_token'));
        Schema::dropIfExists('referral_messages');
    }
};
