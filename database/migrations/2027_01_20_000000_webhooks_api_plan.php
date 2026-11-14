<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 44b: signed outgoing webhooks with a delivery log, the API plan add-on, and the 30-day API request log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->comment('Who added it')->constrained('users')->nullOnDelete();
            $table->string('url', 500);
            $table->string('description', 120)->nullable();
            $table->string('mode', 8)->default('live')->comment('live: real referrals; test: the API sandbox');
            $table->json('events')->comment('Event types to send, or ["*"] for all');
            $table->text('secret')->comment('Signing secret (encrypted)');
            $table->text('previous_secret')->nullable()->comment('Still signed with, for 24 hours after a rotation (encrypted)');
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('failing_since')->nullable()->comment('First failed attempt since the last success');
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 40)->comment('Same for every delivery of one event, so receivers can ignore repeats');
            $table->string('event_type', 60);
            $table->longText('payload');
            $table->string('status', 12)->default('pending')->comment('pending, sending, retrying, succeeded, failed');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable()->comment('First 1,000 characters');
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['webhook_endpoint_id', 'id']);
            $table->index('created_at');
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 40);
            $table->timestamp('created_at');
            $table->index(['team_id', 'id']);
            $table->index('created_at');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('api_plan_since')->nullable()->comment('API plan add-on: on since');
            $table->timestamp('api_plan_until')->nullable()->comment('API plan add-on: switched off at (billed to here)');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['api_plan_since', 'api_plan_until']);
        });
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
