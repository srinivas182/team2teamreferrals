<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verified provider webhooks, stored once each (providers retry; the unique key makes retries harmless).
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 120);
            $table->string('type', 120);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        // AI spend by month, to enforce the admin's monthly cap.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->comment('YYYY-MM');
            $table->string('model', 80);
            $table->string('purpose', 60);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->timestamps();
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('webhook_events');
    }
};
