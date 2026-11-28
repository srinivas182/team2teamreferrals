<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing reward pauses, one row each time a team reaches 3 "not a real client" marks in a group. The group owner
 * (or a super admin) can lift a pause, which starts the count again, or keep it. Kept for escalating repeat cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sharing_reward_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->timestamp('paused_at');
            $table->string('resolution', 10)->nullable(); // lifted | kept
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'group_id', 'paused_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sharing_reward_pauses');
    }
};
