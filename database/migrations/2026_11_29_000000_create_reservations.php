<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pre-launch "Reserve your city": no card, email confirmed, then claimed as a founding application. */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 160);
            $table->string('business', 160)->nullable();
            $table->boolean('on_wall')->default(true)->comment('Show on the founding member wall');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 10)->default('pending')->comment('pending, confirmed, claimed');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('application_id')->nullable()->constrained('seat_applications')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->unique(['group_id', 'city_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
