<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cross-industry introduction is a referral sent from a member of another network.
        Schema::table('referrals', fn (Blueprint $t) => $t->foreignId('introduced_from_group_id')->nullable()->after('group_id')->constrained('groups')->nullOnDelete());
        // Admins can block (or re-allow) specific industry pairs, with a counsel note.
        Schema::create('industry_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_industry_id')->constrained('industries')->cascadeOnDelete();
            $table->foreignId('to_industry_id')->constrained('industries')->cascadeOnDelete();
            $table->boolean('allowed');
            $table->string('note', 500);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['from_industry_id', 'to_industry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_pairings');
        Schema::table('referrals', fn (Blueprint $t) => $t->dropConstrainedForeignId('introduced_from_group_id'));
    }
};
