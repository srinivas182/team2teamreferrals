<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 45a: CRM connectors (Follow Up Boss, BoldTrail, Lofty). One connection per team and CRM, with stage
 * mappings and the CRM record each referral became, and a log of every sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->comment('followupboss, boldtrail, lofty');
            $table->string('status', 12)->default('active')->comment('active, paused (by the team), error (the CRM refused the key)');
            $table->text('credentials')->comment('API key (encrypted)');
            $table->json('settings')->comment('sync_sent, sync_notes, assign_user_id');
            $table->string('account_name', 150)->nullable()->comment('The CRM account, as the CRM names it');
            $table->json('remote_users')->nullable()->comment('Agents in the CRM, for "assign to"');
            $table->json('remote_stages')->nullable()->comment('Stages in the CRM, for mapping');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'provider']);
            $table->index(['provider', 'status']);
        });

        Schema::create('integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->comment('record: the referral')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('stage: CRM stage → our stage key; record: our referral → the CRM contact');
            $table->string('local_value', 100)->comment('stage: our stage key ("" = leave as is); record: referral id');
            $table->string('remote_value', 100)->comment('stage: the CRM stage name; record: the CRM contact id');
            $table->json('meta')->nullable()->comment('record: side (received/sent), last seen CRM stage, checked_at');
            $table->timestamps();
            $table->unique(['integration_id', 'kind', 'remote_value']);
            $table->index(['integration_id', 'kind', 'referral_id']);
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 4)->comment('out: to the CRM; in: from it');
            $table->string('action', 20)->comment('contact, note, stage, check');
            $table->string('status', 10)->default('pending')->comment('pending, ok, retrying, failed, skipped');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->json('payload')->nullable()->comment('What to send (for retries)');
            $table->string('remote_id', 100)->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['integration_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('integrations');
    }
};
