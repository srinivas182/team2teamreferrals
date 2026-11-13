<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('path', 200)->comment('WebP, at most 1600 px wide; location data removed');
            $table->string('thumb_path', 200);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('alt', 160)->comment('Required: describes the image for screen readers and answer engines');
            $table->string('original_name', 160);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('slug', 160)->unique();
            $table->string('title', 160);
            $table->string('answer_summary', 400)->nullable()->comment('Required to publish');
            $table->mediumText('body')->comment('Cleaned HTML');
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer', 120)->nullable()->comment('Reviewed by: a named expert, shown and in Article data');
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('status', 12)->default('draft')->comment('draft, scheduled, published, unpublished');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('seo');
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('location', 20)->unique()->comment('header or footer');
            $table->json('items')->comment('[{label, url}]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('blog_categories');
        Schema::dropIfExists('media');
    }
};
