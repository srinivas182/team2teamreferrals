<?php

use App\Support\TimeZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's own choice is never overwritten by the team-city default.
        Schema::table('users', fn (Blueprint $t) => $t->boolean('timezone_chosen')->default(false)->after('timezone'));
        // Existing members: time zone from their team's first seat city.
        foreach (DB::table('seats')->whereNotNull('primary_team_id')->orderBy('id')->get(['primary_team_id', 'territory_id'])->unique('primary_team_id') as $s) {
            TimeZones::applyCityDefault(DB::table('team_members')->where('team_id', $s->primary_team_id)->pluck('user_id')->all(), (int) $s->territory_id);
        }
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('series_id')->nullable()->comment('First event of a recurring series');
            $table->string('title', 140);
            $table->text('description')->nullable();
            $table->string('kind', 10)->comment('in_person or virtual');
            $table->string('location', 255)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64)->comment('The event’s own zone, shown alongside the viewer’s');
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('sequence')->default(0)->comment('Calendar invite revision');
            $table->string('check_in_code', 16)->unique();
            $table->text('notes')->nullable()->comment('Post-event notes');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'starts_at']);
        });
        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->comment('going, maybe, no, waitlist');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('reminded_day_at')->nullable();
            $table->timestamp('reminded_hour_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });
        Schema::table('feed_posts', fn (Blueprint $t) => $t->unsignedBigInteger('event_id')->nullable()->after('referral_id'));
    }

    public function down(): void
    {
        Schema::table('feed_posts', fn (Blueprint $t) => $t->dropColumn('event_id'));
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('timezone_chosen'));
    }
};
