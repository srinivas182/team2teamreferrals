<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The agreement for one referral: the approved wording as filled in, both signatures with their audit trail, and the signed PDF. */
    public function up(): void
    {
        Schema::create('referral_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->comment('awaiting_approval, pending, signed');
            $table->string('scope', 8)->nullable()->comment('industry or group');
            $table->unsignedInteger('version')->nullable();
            $table->json('sections')->nullable();
            $table->json('summary')->nullable();
            $table->string('content_hash', 64)->nullable()->comment('sha256 of the sections; proves the signed text never changed');
            foreach (['sender', 'receiver'] as $side) {
                $table->foreignId("{$side}_signer_id")->nullable()->constrained('users')->nullOnDelete();
                $table->string("{$side}_signed_name", 160)->nullable();
                $table->timestamp("{$side}_signed_at")->nullable();
                $table->string("{$side}_ip", 45)->nullable();
                $table->string("{$side}_device", 255)->nullable();
            }
            $table->string('pdf_path')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_agreements');
    }
};
