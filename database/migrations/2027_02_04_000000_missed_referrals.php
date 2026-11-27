<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals a team missed because its seat was inactive (unpaid): the client went to the backup team or elsewhere.
 * Shown on the Renew page ("You missed 4 referrals") so the team sees what paying gets back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $t->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $t->string('client', 80)->comment('Short name only ("Tom B.")');
            $t->timestamp('created_at')->nullable();
            $t->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_referrals');
    }
};
