<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('post, ask, announcement, win');
            $table->text('body');
            $table->foreignId('city_id')->nullable()->comment('For "Ask the network"')->constrained()->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->comment('For win announcements')->constrained()->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedSmallInteger('reports')->default(0);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'removed_at', 'id']);
        });
        Schema::create('feed_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('offer')->default(false)->comment('On an Ask: the commenter holds the seat for that city');
            $table->unsignedSmallInteger('reports')->default(0);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'id']);
        });
        Schema::create('feed_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('like, celebrate, thanks');
            $table->timestamp('created_at');
            $table->unique(['post_id', 'user_id']);
        });
        Schema::create('feed_reports', function (Blueprint $table) {
            $table->id();
            $table->string('target', 8)->comment('post or comment');
            $table->unsignedBigInteger('target_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at');
            $table->unique(['target', 'target_id', 'user_id']);
        });
        // A win is posted only when both teams on the referral agree.
        Schema::create('win_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('sender_ok_at')->nullable();
            $table->timestamp('receiver_ok_at')->nullable();
            $table->foreignId('post_id')->nullable()->constrained('feed_posts')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('feed_digest', 8)->default('weekly')->comment('daily, weekly or off');
            $t->timestamp('feed_digest_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['feed_digest', 'feed_digest_at']));
        foreach (['win_shares', 'feed_reports', 'feed_reactions', 'feed_comments', 'feed_posts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
