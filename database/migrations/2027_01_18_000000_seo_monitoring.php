<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO and answer-engine monitoring (Sprint 43b): AI crawler and AI referral visits, pages that are gone for good,
 * nightly site checks, and the monthly answer-engine visibility check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ai_visits', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('kind', 10)->comment('crawler (a bot read the page) or referral (a person arrived from an AI assistant)');
            $table->string('source', 40)->comment('GPTBot, PerplexityBot… or chatgpt.com, perplexity.ai…');
            $table->string('path', 255);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['day', 'kind', 'source', 'path']);
            $table->index(['kind', 'day']);
        });
        Schema::create('gone_urls', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255)->unique()->comment('A public page that was removed: answers 410 Gone so search engines drop it');
            $table->string('reason', 40);
            $table->timestamp('gone_at');
        });
        Schema::create('seo_checks', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at')->index();
            $table->string('trigger', 10)->comment('nightly, manual or release');
            $table->unsignedInteger('pages');
            $table->unsignedInteger('failed');
            $table->json('summary')->comment('Counts: page problems, broken links, duplicate titles and descriptions, near-duplicate pages');
            $table->json('details')->comment('Problems per page, broken links with the pages linking to them, duplicate groups');
        });
        Schema::create('aeo_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question', 300);
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('auto')->comment('auto (from published industries and filled cities) or admin');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique('question');
        });
        Schema::create('aeo_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('aeo_questions')->cascadeOnDelete();
            $table->string('engine', 12)->comment('openai, perplexity, anthropic or google');
            $table->char('month', 7)->comment('YYYY-MM');
            $table->boolean('cited_platform')->default(false);
            $table->boolean('cited_member')->default(false);
            $table->json('citations')->comment('Every address the answer cited');
            $table->text('excerpt')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('asked_at');
            $table->unique(['question_id', 'engine', 'month']);
            $table->index(['month', 'engine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeo_results');
        Schema::dropIfExists('aeo_questions');
        Schema::dropIfExists('seo_checks');
        Schema::dropIfExists('gone_urls');
        Schema::dropIfExists('seo_ai_visits');
    }
};
