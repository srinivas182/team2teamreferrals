<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Website pages built with blocks. The blocks live in each version (an ordered list), so every version is a
        // complete snapshot: preview and rollback are exact.
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('slug', 160)->unique()->comment('Address without the leading slash; "" is never allowed (the home page is the app)');
            $table->string('template', 12)->default('page')->comment('page, landing, legal');
            $table->string('status', 10)->default('draft')->comment('draft, scheduled, published, unpublished');
            $table->timestamp('publish_at')->nullable();
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->string('published_slug', 160)->nullable()->comment('The address it was last published at, for automatic redirects');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 160);
            $table->string('answer_summary', 400)->nullable()->comment('The one or two sentences answer engines quote; required to publish');
            $table->json('seo')->comment('title, description, image, noindex');
            $table->json('blocks');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['page_id', 'number']);
        });
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 200)->unique();
            $table->string('to_path', 300);
            $table->unsignedSmallInteger('code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->boolean('automatic')->default(false)->comment('Created when a page address changed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('cms_page_versions');
        Schema::dropIfExists('cms_pages');
    }
};
