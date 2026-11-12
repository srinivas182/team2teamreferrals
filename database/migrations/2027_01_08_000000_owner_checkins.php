<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An owner's friendly check-in with a team that's struggling (at most one a week per team).
        Schema::create('owner_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->timestamp('created_at');
            $table->index(['group_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_checkins');
    }
};
