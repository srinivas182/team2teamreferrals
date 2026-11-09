<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('visibility', 12)->default('open')->after('status')->comment('open: anyone can apply; invite_only: by invitation');
            $table->string('photo_path')->nullable()->after('description');
        });

        // Group owners approve applications and run the group; they never change pricing.
        Schema::create('group_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
        });

        // Pricing or rule changes that take effect on a future date; members are told 30 days ahead.
        Schema::create('group_scheduled_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('section', 12)->comment('pricing or rules');
            $table->json('values');
            $table->date('effective_on');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['applied_at', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_scheduled_changes');
        Schema::dropIfExists('group_owners');
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn(['visibility', 'photo_path']));
    }
};
