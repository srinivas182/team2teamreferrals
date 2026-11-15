<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 46b: drip email campaigns to prospects (steps, who's in each, every email sent and what happened to it),
 * the ready-made "finish joining" sequence for abandoned sign-ups, and where each sign-up came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 120);
            $table->string('kind', 12)->default('drip')->comment('drip: people you enrol or who match; abandoned: signed up but didn’t apply');
            $table->string('status', 10)->default('draft')->comment('draft, active, paused');
            $table->json('audience')->nullable()->comment('drip: prospect filters that enrol automatically (stage, source, industry, state, temp); null = only people you add');
            $table->string('goal', 12)->default('joined')->comment('Exit and count as converted at: applied or joined');
            $table->unsignedTinyInteger('window_start')->default(9)->comment('Send between these hours, in each person’s time zone');
            $table->unsignedTinyInteger('window_end')->default(17);
            $table->boolean('weekdays_only')->default(true);
            $table->unsignedSmallInteger('daily_cap')->default(200);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unsignedInteger('wait_hours')->comment('After enrolling (first step) or after the previous email');
            $table->string('subject', 150);
            $table->string('subject_b', 150)->nullable()->comment('A/B test: the other subject line');
            $table->char('winner', 1)->nullable()->comment('a or b, once the test is decided');
            $table->text('body');
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'position']);
        });

        Schema::create('campaign_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('active')->comment('active, completed, exited, converted');
            $table->string('exit_reason', 30)->nullable()->comment('joined, applied, replied, unsubscribed, bounced, complained, removed, campaign_deleted');
            $table->unsignedTinyInteger('next_position')->default(1);
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('ended_at')->nullable();
            $table->unique(['campaign_id', 'prospect_id']);
            $table->index(['status', 'next_send_at']);
        });

        Schema::create('campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique()->comment('In the tracking links and the reply address');
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->char('variant', 1)->default('a');
            $table->string('subject', 150);
            $table->string('status', 10)->comment('sent, skipped, test');
            $table->string('skip_reason', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'campaign_step_id', 'variant']);
            $table->index(['prospect_id', 'id']);
        });

        Schema::create('signup_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_source', 100)->nullable();
            $table->string('first_medium', 100)->nullable();
            $table->string('first_campaign', 100)->nullable();
            $table->string('first_landing', 255)->nullable();
            $table->string('first_referrer', 255)->nullable();
            $table->timestamp('first_at')->nullable();
            $table->string('last_source', 100)->nullable();
            $table->string('last_medium', 100)->nullable();
            $table->string('last_campaign', 100)->nullable();
            $table->string('last_landing', 255)->nullable();
            $table->timestamp('last_at')->nullable();
            $table->foreignId('campaign_id')->nullable()->comment('The campaign email they last clicked')->constrained()->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['first_source', 'created_at']);
            $table->index('campaign_id');
        });

        // The ready-made "finish joining" sequence for people who sign up but don't apply for a seat.
        $id = DB::table('campaigns')->insertGetId(['ulid' => strtolower((string) Illuminate\Support\Str::ulid()), 'name' => 'Finish joining (abandoned sign-up)', 'kind' => 'abandoned', 'status' => 'active',
            'goal' => 'applied', 'window_start' => 8, 'window_end' => 20, 'weekdays_only' => false, 'daily_cap' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $steps = [
            [1, 'Your {city} seat is waiting, {first_name}', null, "Hi {first_name},\n\nYou started joining Team to Team Referrals but didn't pick your network and city yet. It takes two minutes, and the first months are free.\n\nThe team that holds a city receives the network's referrals there, so it's worth claiming yours before someone else does.", 'Pick your city'],
            [24, 'Is your city still open?', 'Quick question about your territory', "Hi {first_name},\n\nSeats are one team per city in each network. If yours is taken you can still be its backup (you get its referrals when the holder can't take them) or join the waitlist.\n\nHave a look at who holds your area and apply in a couple of clicks.", 'See your city'],
            [96, 'Anything we can help with?', null, "Hi {first_name},\n\nIf something stopped you finishing (a question about fees, licences or how referrals work), just reply to this email and a person will answer.\n\nOr pick up where you left off: your account is ready.", 'Finish joining'],
        ];
        foreach ($steps as $i => [$wait, $subject, $subjectB, $body, $cta]) {
            DB::table('campaign_steps')->insert(['campaign_id' => $id, 'position' => $i + 1, 'wait_hours' => $wait, 'subject' => $subject, 'subject_b' => $subjectB, 'body' => $body, 'cta_label' => $cta,
                'cta_url' => '/app/groups', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_attributions');
        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaign_enrollments');
        Schema::dropIfExists('campaign_steps');
        Schema::dropIfExists('campaigns');
    }
};
