<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Devices a user has signed in from: shown on the security page, used for new-device alerts and "remember this device" for 2FA.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique()->comment('SHA-256 of the long-lived device cookie');
            $table->string('label', 120)->comment('e.g. Chrome on macOS');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('two_factor_trusted_until')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
