<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications (Laravel's database channel).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // Only the member's changes from the catalogue defaults are stored.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('channel', 12);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['user_id', 'type', 'channel']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->time('quiet_hours_start')->nullable()->after('timezone');
            $table->time('quiet_hours_end')->nullable()->after('quiet_hours_start');
            $table->boolean('email_digest')->default(false)->after('quiet_hours_end')->comment('One morning email for non-urgent updates');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('label', 120)->nullable();
            $table->timestamps();
        });

        // Every saved version is kept; exactly one per type and channel is active.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->string('channel', 12);
            $table->unsignedInteger('version');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'channel', 'version']);
            $table->index(['type', 'channel', 'is_active']);
        });

        // Numbers that replied STOP. Nothing is texted to them until they reply START.
        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->timestamp('opted_out_at');
        });

        // Non-urgent email and text held for bundling, quiet hours or the daily digest.
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('channel', 12);
            $table->string('title');
            $table->text('body');
            $table->string('url')->nullable();
            $table->timestamp('deliver_after');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['sent_at', 'deliver_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('sms_opt_outs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'email_digest']));
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
