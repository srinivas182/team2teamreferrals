<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the holder's score first fell below the group's threshold (cleared when it recovers).
        Schema::table('seats', fn (Blueprint $t) => $t->date('below_since')->nullable()->after('inactive_at'));
        Schema::create('seat_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holder_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('challenger_team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('status', 16)->comment('open, recovered, pending, appealed, transferred, overturned, cancelled');
            $table->unsignedTinyInteger('threshold');
            $table->unsignedTinyInteger('score_at_open');
            $table->unsignedTinyInteger('score_at_end')->nullable();
            $table->timestamp('window_ends_at');
            $table->timestamp('appeal_until')->nullable();
            $table->string('holder_choice', 10)->default('waitlist')->comment('backup, waitlist or leave, if the seat moves');
            $table->text('appeal_reason')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'window_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_challenges');
        Schema::table('seats', fn (Blueprint $t) => $t->dropColumn('below_since'));
    }
};
