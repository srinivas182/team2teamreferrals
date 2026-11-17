<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 52b: usability tests (testers, what happened during each test, and the fix list it feeds) and the founding-member
 * launch emails, added as a draft Growth campaign for the owner to review before anything is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usability_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('family', 20)->comment('Industry family the script is written for (Industry::FAMILIES)');
            $table->string('industry_slug', 60)->nullable()->comment('The industry whose words the script uses');
            $table->json('journeys')->comment('Journey keys, in the order the tester does them');
            $table->string('status', 10)->default('invited')->comment('invited, started, finished');
            $table->unsignedTinyInteger('current')->default(0)->comment('Index of the task the tester is on');
            $table->json('results')->nullable()->comment('Task key => done or gave_up, with seconds taken and a note');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['family', 'status']);
        });

        Schema::create('usability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('usability_sessions')->cascadeOnDelete();
            $table->string('task', 20)->nullable();
            $table->string('kind', 12)->comment('page, stuck, rage, error, help, done, gave_up');
            $table->string('path', 300);
            $table->string('detail', 500)->nullable()->comment('What was clicked, which fields had errors or the tester’s note; never what they typed');
            $table->unsignedInteger('seconds')->nullable()->comment('Time on the page or the task');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['session_id', 'id']);
        });

        Schema::create('usability_issues', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique()->comment('kind + task + page, so the same problem is counted once');
            $table->string('title', 200);
            $table->string('task', 20)->nullable();
            $table->string('kind', 12);
            $table->string('path', 300);
            $table->string('severity', 8)->default('medium')->comment('high, medium, low');
            $table->string('status', 10)->default('open')->comment('open, planned, fixed, wont_fix');
            $table->unsignedInteger('hits')->default(0);
            $table->json('testers')->nullable()->comment('Session ids that ran into it');
            $table->json('families')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        // Three founding-member launch emails, as a draft: nothing is sent until the owner reviews and activates it.
        if (Schema::hasTable('campaigns') && ! DB::table('campaigns')->where('name', 'Launch: founding members')->exists()) {
            $id = DB::table('campaigns')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'name' => 'Launch: founding members', 'kind' => 'drip', 'status' => 'draft',
                'audience' => json_encode(['source' => 'reservation']), 'goal' => 'joined', 'window_start' => 9, 'window_end' => 17, 'weekdays_only' => true, 'daily_cap' => 500,
                'created_at' => now(), 'updated_at' => now()]);
            $steps = [
                [0, 'We’re live: claim your seat in {city}', 'Doors are open, {first_name}: claim {city}',
                    "Hi {first_name},\n\nTeam to Team Referrals is live, and the seat you reserved in {city} is being held for you.\n\nClaim it now to keep your founding-member price for as long as you hold the seat. It takes about two minutes: create your account, confirm your licence, and the seat is yours. The first months are free.\n\nOnce you’re in, other teams in your network can send you their clients moving to {city}, and you can send yours anywhere they hold a seat.",
                    'Claim your seat', '/register'],
                [72, 'Your reservation in {city} is still waiting', null,
                    "Hi {first_name},\n\nA quick reminder: your founding-member reservation for {city} is still waiting for you to claim it.\n\nWhat you get as a founding member:\n- The price you reserved at, for as long as you hold the seat.\n- One verified pro per city in your network, so referrals for {city} come to you.\n- A free trial before your first bill.\n\nIf something is stopping you, reply to this email and a person will answer.",
                    'Claim your seat', '/register'],
                [96, 'Last call for your founding-member price in {city}', null,
                    "Hi {first_name},\n\nWe’re about to open {city} to other teams in your network. If you’d still like it, claim it now and your founding-member price stays with you.\n\nIf now isn’t the right time, no problem: you won’t hear from us about this reservation again.",
                    'Claim your seat now', '/register'],
            ];
            foreach ($steps as $i => [$wait, $subject, $subjectB, $body, $cta, $url]) {
                DB::table('campaign_steps')->insert(['campaign_id' => $id, 'position' => $i + 1, 'wait_hours' => $wait, 'subject' => $subject, 'subject_b' => $subjectB, 'body' => $body,
                    'cta_label' => $cta, 'cta_url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaigns')) {
            DB::table('campaigns')->where(['name' => 'Launch: founding members', 'status' => 'draft'])->delete();
        }
        Schema::dropIfExists('usability_issues');
        Schema::dropIfExists('usability_events');
        Schema::dropIfExists('usability_sessions');
    }
};
