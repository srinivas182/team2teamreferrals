<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60);
            $table->string('holder_name', 160)->nullable()->comment('Name on the license, if not the team');
            $table->date('expires_on')->nullable();
            $table->string('document_path')->nullable()->comment('Private disk; opened only through expiring links');
            $table->string('status', 10)->default('pending')->comment('pending, verified, rejected, expired');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reminded_30_at')->nullable();
            $table->timestamp('reminded_7_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'state_id', 'number']);
            $table->index(['status', 'created_at']);
            $table->index(['state_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_licenses');
    }
};
