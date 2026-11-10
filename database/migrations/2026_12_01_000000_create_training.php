<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One training per industry (lessons + quiz). Bumping min_version asks members to re-train after a material change.
        Schema::create('training_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('min_version')->default(1)->comment('Passes below this version must re-train');
            $table->json('lessons');
            $table->json('questions');
            $table->unsignedTinyInteger('pass_mark')->default(80);
            $table->timestamps();
        });

        Schema::create('training_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('training_modules')->cascadeOnDelete();
            $table->json('lessons_done')->nullable();
            $table->unsignedTinyInteger('best_score')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('missed')->nullable()->comment('Question indexes to retake');
            $table->unsignedInteger('passed_version')->nullable();
            $table->timestamp('passed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'module_id']);
        });

        // A super admin can require training for every group in an industry (e.g. Tier B compliance); owners can't switch it off.
        Schema::table('industries', fn (Blueprint $t) => $t->boolean('training_locked')->default(false)->after('license_required'));
        DB::table('industries')->where('tier', 'B')->update(['training_locked' => true]);

        // Launch groups start with training required and a 14-day grace from today.
        foreach (DB::table('groups')->get(['id', 'rules']) as $g) {
            $rules = json_decode((string) $g->rules, true) ?: [];
            $rules += ['training_required' => true, 'training_grace_days' => 14, 'training_since' => now()->toDateString()];
            DB::table('groups')->where('id', $g->id)->update(['rules' => json_encode($rules)]);
        }
    }

    public function down(): void
    {
        Schema::table('industries', fn (Blueprint $t) => $t->dropColumn('training_locked'));
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_modules');
    }
};
