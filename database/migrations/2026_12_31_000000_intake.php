<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What came in (a forwarded email, a voice note, a photo). The content is deleted once the draft is sent or after 14 days.
        Schema::create('intake_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 8)->comment('email, voice, photo');
            $table->string('message_id', 255)->nullable()->unique();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('media_path', 255)->nullable();
            $table->string('status', 10)->comment('received, drafted, ignored');
            $table->unsignedInteger('processing_ms')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
        // A referral prepared from intake, waiting for the member to check and send it.
        Schema::create('referral_drafts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intake_id')->nullable()->constrained('intake_messages')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name', 120)->nullable();
            $table->string('client_phone', 32)->nullable();
            $table->string('client_email', 190)->nullable();
            $table->text('notes')->nullable();
            $table->json('review')->comment('Fields to check: missing, uncertain or several candidates');
            $table->boolean('ai_used')->default(false);
            $table->string('status', 10)->default('pending')->comment('pending, sent, discarded, expired');
            $table->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_drafts');
        Schema::dropIfExists('intake_messages');
    }
};
