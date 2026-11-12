<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->comment('Set for an owner report: only that network, checked on every run')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('dataset', 20)->comment('referrals, revenue, seats, teams');
            $table->json('columns');
            $table->json('filters');
            $table->string('group_by', 20)->nullable();
            $table->string('preset', 12)->default('last_30');
            $table->timestamps();
        });
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('saved_reports')->cascadeOnDelete();
            $table->string('frequency', 8)->comment('weekly or monthly');
            $table->unsignedTinyInteger('weekday')->nullable()->comment('1 Monday … 7 Sunday');
            $table->unsignedTinyInteger('day')->nullable()->comment('Day of the month, 1–28');
            $table->unsignedTinyInteger('hour');
            $table->string('timezone', 40);
            $table->json('recipients')->comment('User ids');
            $table->boolean('paused')->default(false);
            $table->timestamp('next_run_at');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['paused', 'next_run_at']);
        });
        // Every attempt to deliver a report to someone, with its outcome.
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->comment('sent, retrying, failed, skipped');
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->unsignedInteger('rows')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('saved_reports');
    }
};
