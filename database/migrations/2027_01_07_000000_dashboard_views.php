<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's saved dashboard views: a name, a period (preset or custom dates), and one default per dashboard.
        Schema::create('dashboard_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dashboard', 60)->comment('team, or group:{id}');
            $table->string('name', 60);
            $table->string('preset', 12);
            $table->date('from')->nullable();
            $table->date('to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'dashboard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_views');
    }
};
