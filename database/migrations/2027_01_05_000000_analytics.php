<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where each source has been shipped up to: shipping resumes here, and a backfill starts from zero.
        Schema::create('analytics_cursors', function (Blueprint $table) {
            $table->string('source', 40)->primary();
            $table->string('position', 60)->default('0');
            $table->unsignedBigInteger('shipped')->default(0);
            $table->timestamp('shipped_at')->nullable();
        });
        // The MySQL analytics store (used until ClickHouse is switched on). No client personal data: IDs, types, amounts, times.
        Schema::connection(config('analytics.connection'))->create('analytics_events', function (Blueprint $table) {
            $table->string('event_key', 80)->primary();
            $table->string('event_type', 40);
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('counterpart_team_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('industry_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('referral_id')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->bigInteger('value')->default(1);
            $table->index(['team_id', 'occurred_at']);
            $table->index(['group_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
        // Daily rollups per team and per network, rebuilt for the days a batch touches: what dashboards read.
        Schema::connection(config('analytics.connection'))->create('analytics_daily', function (Blueprint $table) {
            $table->date('day');
            $table->string('scope', 6)->comment('team or group');
            $table->unsignedBigInteger('scope_id');
            $table->string('event_type', 40);
            $table->unsignedBigInteger('events');
            $table->bigInteger('value');
            $table->bigInteger('amount_cents');
            $table->primary(['scope', 'scope_id', 'day', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('analytics.connection'))->dropIfExists('analytics_daily');
        Schema::connection(config('analytics.connection'))->dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_cursors');
    }
};
