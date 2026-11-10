<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('referral_messages')->nullOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 180);
            $table->string('path')->comment('Private disk; quarantine/ when infected');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('status', 12)->default('pending')->comment('pending, clean, infected, unscanned');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['referral_id', 'message_id']);
        });
        // Each inbound email is handled once, however often the provider retries.
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('status', 20);
            $table->timestamp('received_at');
        });
        Schema::table('notification_outbox', fn (Blueprint $t) => $t->string('reply_to')->nullable()->after('url'));
    }

    public function down(): void
    {
        Schema::table('notification_outbox', fn (Blueprint $t) => $t->dropColumn('reply_to'));
        Schema::dropIfExists('inbound_emails');
        Schema::dropIfExists('referral_attachments');
    }
};
