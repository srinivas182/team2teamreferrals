<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Give and get: every entry points at the referral that earned it, once per kind, so balances always reconcile.
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('sent_accepted, closed_won, received');
            $table->integer('credits');
            $table->timestamp('created_at');
            $table->unique(['team_id', 'referral_id', 'kind']);
            $table->index(['team_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
