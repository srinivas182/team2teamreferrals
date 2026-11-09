<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform settings edited from the admin (providers, branding, modules). Secrets are encrypted at rest.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique()->comment('e.g. mail.driver, sms.twilio.token');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every email and text the platform sends, with its delivery status.
        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 12)->comment('mail, sms');
            $table->string('provider', 24);
            $table->string('recipient');
            $table->string('subject')->nullable()->comment('email subject or message type');
            $table->string('status', 16)->default('sent')->comment('sent, delivered, failed, bounced');
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['channel', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('settings');
    }
};
