<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly insights for network owners: the figures, and a short summary (AI-written, or plain when AI is off).
        Schema::create('group_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->date('week');
            $table->json('data');
            $table->text('summary');
            $table->boolean('ai_written')->default(false);
            $table->timestamp('created_at');
            $table->unique(['group_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_insights');
    }
};
