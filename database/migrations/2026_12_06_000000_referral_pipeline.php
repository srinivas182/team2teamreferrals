<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $t) {
            $t->string('stage', 40)->nullable()->after('status')->comment('Pipeline stage key, once accepted');
            $t->timestamp('stage_changed_at')->nullable()->after('stage');
            $t->timestamp('first_contact_at')->nullable()->after('accepted_at');
            $t->timestamp('last_update_at')->nullable()->after('first_contact_at');
            $t->date('closing_date')->nullable()->after('last_update_at');
            $t->unsignedBigInteger('closing_value_cents')->nullable()->after('closing_date')->comment('Feeds closing and fee steps (Sprint 20)');
            $t->string('lost_reason', 500)->nullable()->after('closing_value_cents');
            $t->timestamp('closed_at')->nullable()->after('lost_reason');
            $t->timestamp('last_nudged_at')->nullable()->after('closed_at')->comment("Sender's polite nudge, at most every 3 days");
            $t->index(['receiver_team_id', 'stage']);
        });
        // Each reminder or nudge is sent once per deadline cycle.
        Schema::create('referral_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('cycle', 40)->comment('What the reminder is for, e.g. the stage or the last-activity time');
            $table->timestamp('sent_at');
            $table->unique(['referral_id', 'kind', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_reminders');
        Schema::table('referrals', function (Blueprint $t) {
            $t->dropIndex(['receiver_team_id', 'stage']);
            $t->dropColumn(['stage', 'stage_changed_at', 'first_contact_at', 'last_update_at', 'closing_date', 'closing_value_cents', 'lost_reason', 'closed_at', 'last_nudged_at']);
        });
    }
};
