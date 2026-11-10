<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approved time off: new referrals go to the backup, and the score doesn't count these days.
        Schema::create('team_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'starts_on', 'ends_on']);
        });
        // One report card per team per month (kept, so members can look back).
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7)->comment('YYYY-MM');
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('data');
            $table->timestamp('created_at');
            $table->unique(['team_id', 'period']);
        });
        Schema::table('teams', fn (Blueprint $t) => $t->timestamp('score_warned_at')->nullable()->after('quick_replies'));
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $t) => $t->dropColumn('score_warned_at'));
        Schema::dropIfExists('report_cards');
        Schema::dropIfExists('team_time_off');
    }
};
