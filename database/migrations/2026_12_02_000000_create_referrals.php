<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('routed_to', 8)->comment('holder or backup');
            $table->string('status', 10)->default('sent')->comment('sent, accepted, declined, expired, withdrawn');
            $table->string('client_name', 160);
            $table->string('client_phone', 20)->nullable();
            $table->string('client_email')->nullable();
            $table->json('answers')->comment('The referral form, as answered');
            $table->text('notes')->nullable();
            $table->timestamp('consent_at');
            $table->string('consent_method', 20)->comment('sender_attested now; client_confirmed in 16b');
            $table->text('consent_text');
            $table->timestamp('respond_by');
            $table->timestamp('accepted_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
            $table->index(['receiver_team_id', 'status']);
            $table->index(['sender_team_id', 'created_at']);
            $table->index(['status', 'respond_by']);
            $table->index(['group_id', 'client_phone']);
        });

        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('event', 24);
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');
            $table->index(['referral_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('referrals');
    }
};
