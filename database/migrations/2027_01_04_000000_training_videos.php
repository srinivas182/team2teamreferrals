<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A training video script: written (or AI-drafted), checked, approved, rendered, then published.
        Schema::create('video_scripts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->comment('Set for a network video; null for the industry default')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('lesson_index')->nullable()->comment('Industry training: which lesson gets the video');
            $table->string('title', 140);
            $table->text('script');
            $table->string('status', 12)->comment('draft, submitted, approved, rendering, ready, failed, published, retired');
            $table->string('avatar_id', 80)->nullable();
            $table->string('voice_id', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('team_id')->nullable()->comment('The team whose credits pay; null when the platform pays')->constrained()->nullOnDelete();
            $table->unsignedInteger('credits')->default(0);
            $table->string('video_path', 255)->nullable();
            $table->string('captions_path', 255)->nullable();
            $table->json('previous')->nullable()->comment('Industry lessons before publishing, for rollback');
            $table->boolean('retake')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['industry_id', 'group_id', 'status']);
        });
        // Each attempt at the provider, with its outcome.
        Schema::create('video_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained('video_scripts')->cascadeOnDelete();
            $table->string('provider_video_id', 120)->nullable()->unique();
            $table->string('status', 12)->comment('processing, ready, failed');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('error', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
        // A network's own video and its retake date: members must have passed since then.
        Schema::table('groups', fn (Blueprint $t) => $t->timestamp('training_retake_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('training_retake_at'));
        Schema::dropIfExists('video_renders');
        Schema::dropIfExists('video_scripts');
    }
};
