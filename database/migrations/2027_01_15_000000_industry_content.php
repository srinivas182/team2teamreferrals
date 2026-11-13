<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Industry hubs (Sprint 39): one reviewed content record per industry, and the page a correction is about. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->unique()->constrained('industries')->cascadeOnDelete();
            $table->string('status', 12)->default('draft')->comment('draft or published');
            $table->json('content')->comment('Summary, facts, FAQs, checklists, glossary, sources: see IndustryContent::RULES');
            $table->string('reviewer', 120)->nullable()->comment('The expert who checked the facts; required to publish');
            $table->date('reviewed_on')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->string('page', 200)->nullable()->after('topic')->comment('For corrections: the page they are about');
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', fn (Blueprint $table) => $table->dropColumn('page'));
        Schema::dropIfExists('industry_content');
    }
};
