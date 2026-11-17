<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 47b: partner billing (flat monthly or annual price per city, free trial, cancel), the per-city cap with a
 * waitlist, listing edits that wait for review, daily stats, and members sharing a partner with a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('billing_cycle', 8)->default('monthly'); // monthly, annual
            // trial, active, past_due (payment retrying; still shown), paused (payment failed; hidden), cancelled
            $table->string('billing_status', 12)->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('trial_reminded_at')->nullable();
            $table->date('paid_until')->nullable();
            $table->date('cancel_at')->nullable();
            $table->json('pending_changes')->nullable();
            $table->timestamp('changes_submitted_at')->nullable();
        });

        Schema::table('partner_listings', function (Blueprint $table) {
            $table->timestamp('waitlisted_at')->nullable(); // status "waitlist": the city is full for this category
        });

        Schema::create('partner_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->default(0); // 0: not tied to a city (the partner's own page)
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('enquiries')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unique(['partner_id', 'city_id', 'day']);
            $table->index(['partner_id', 'day']);
        });

        Schema::create('partner_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_shares');
        Schema::dropIfExists('partner_stats');
        Schema::table('partner_listings', fn (Blueprint $t) => $t->dropColumn('waitlisted_at'));
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn(['billing_cycle', 'billing_status', 'trial_ends_at', 'trial_reminded_at', 'paid_until', 'cancel_at', 'pending_changes', 'changes_submitted_at']));
    }
};
