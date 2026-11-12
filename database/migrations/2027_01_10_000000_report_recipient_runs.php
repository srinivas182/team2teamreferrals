<?php

use App\Domains\Platform\Reports\Schedules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each recipient's own next send time: the chosen hour in their own time zone.
        Schema::create('report_recipient_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('next_run_at');
            $table->timestamp('last_run_at')->nullable();
            $table->unique(['schedule_id', 'user_id']);
            $table->index('next_run_at');
        });
        // Carry existing schedules over: one row per recipient.
        foreach (DB::table('report_schedules')->get() as $s) {
            foreach ((array) json_decode((string) $s->recipients, true) as $uid) {
                if (DB::table('users')->where('id', (int) $uid)->exists()) {
                    DB::table('report_recipient_runs')->insert(['schedule_id' => $s->id, 'user_id' => (int) $uid,
                        'next_run_at' => Schedules::nextRun((string) $s->frequency, $s->weekday, $s->day, (int) $s->hour, Schedules::zoneFor((int) $uid, (string) $s->timezone))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_recipient_runs');
    }
};
