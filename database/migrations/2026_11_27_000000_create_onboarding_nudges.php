<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_nudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('day2, day7');
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_nudges');
    }
};
