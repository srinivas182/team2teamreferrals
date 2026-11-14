<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Find a trusted pro" (Sprint 41): requests from the public, and where each referral came from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('phone', 20);
            $table->string('email', 190)->nullable();
            $table->string('contact_method', 8)->default('call')->comment('call, text or email');
            $table->string('best_time', 12)->nullable()->comment('morning, afternoon, evening, anytime');
            $table->string('timing', 10)->comment('now, weeks, months, exploring: how soon they need help');
            $table->json('answers');
            $table->text('consent_text');
            $table->timestamp('consent_at');
            $table->string('code_hash', 64)->nullable()->comment('Phone check code (hashed), cleared once checked');
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('status', 12)->default('unverified')->comment('unverified, routed, board, claimed, duplicate, closed');
            $table->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_path', 200)->nullable()->comment('The public page the request was made on');
            $table->unsignedInteger('fee_cents')->nullable()->comment('Per-request fee, recorded on acceptance only when the setting is on');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status']);
            $table->index(['phone', 'created_at']);
        });
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('source', 10)->default('member')->after('routed_to')->comment('member (sent by a team) or consumer (asked for on the website)');
            $table->index(['receiver_team_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['receiver_team_id', 'source']);
            $table->dropColumn('source');
        });
        Schema::dropIfExists('consumer_requests');
    }
};
