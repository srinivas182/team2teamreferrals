<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Groups: one launch group per industry now; owners, seats and the full admin arrive in Sprint 10.
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 12)->default('draft')->comment('draft, live, closed');
            $table->boolean('is_launch')->default(false)->comment('The first group of its industry');
            $table->json('rules');
            $table->json('pricing');
            $table->timestamps();
            $table->index(['industry_id', 'status']);
        });

        // Starter licensing rows are suggestions until an admin reviews them; only reviewed rows count as coverage.
        Schema::table('industry_licensing', fn (Blueprint $t) => $t->boolean('needs_review')->default(false)->after('notes'));
    }

    public function down(): void
    {
        Schema::table('industry_licensing', fn (Blueprint $t) => $t->dropColumn('needs_review'));
        Schema::dropIfExists('groups');
    }
};
