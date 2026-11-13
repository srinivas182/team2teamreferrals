<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('topic', 40);
            $table->text('message');
            $table->string('ip_hash', 64);
            $table->string('status', 10)->default('new')->comment('new or handled');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        // Member testimonials with recorded consent: only these become Reviews in structured data.
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('quote', 600);
            $table->string('author_name', 120);
            $table->string('business', 160)->nullable();
            $table->string('consent_text', 300);
            $table->timestamp('consented_at');
            $table->string('status', 10)->default('pending')->comment('pending, approved, declined, withdrawn');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('contact_messages');
    }
};
