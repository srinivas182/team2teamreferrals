<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 45b: each person's calendar. Google or Microsoft connections (tokens encrypted), the calendar entry each
 * item became there, and a private calendar link (.ics) that works in any calendar app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 12)->comment('google, microsoft');
            $table->string('status', 10)->default('active')->comment('active, error (the sign-in expired or was removed)');
            $table->text('credentials')->comment('refresh_token, access_token, expires_at (encrypted)');
            $table->string('account_email', 255)->nullable();
            $table->string('calendar_id', 255)->default('primary');
            $table->string('calendar_name', 255)->nullable();
            $table->json('kinds')->comment('events, deadlines, nurture');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('calendar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 60)->comment('e.g. event:12, respond:01J…');
            $table->string('remote_id', 255);
            $table->string('hash', 40)->comment('What was last written, to skip unchanged items');
            $table->timestamps();
            $table->unique(['calendar_connection_id', 'item_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_feed_hash', 64)->nullable()->unique()->comment('sha256 of the private calendar link token');
            $table->text('calendar_feed_token')->nullable()->comment('The private calendar link token (encrypted), so the link can be shown again');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_feed_hash']);
            $table->dropColumn(['calendar_feed_hash', 'calendar_feed_token']);
        });
        Schema::dropIfExists('calendar_items');
        Schema::dropIfExists('calendar_connections');
    }
};
