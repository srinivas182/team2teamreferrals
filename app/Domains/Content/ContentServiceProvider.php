<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Content\Http\AdminBlogController;
use App\Domains\Content\Http\AdminContentController;
use App\Domains\Content\Http\BlogController;
use App\Domains\Content\Http\PublicPageController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The website: pages built from blocks, served only where the app has no route; Admin → Website to edit them. */
final class ContentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:content.manage'])->prefix('admin/content')->group(function (): void {
                Route::get('/', [AdminContentController::class, 'index'])->name('admin.content');
                Route::get('new', [AdminContentController::class, 'edit'])->name('admin.content.new');
                Route::post('/', [AdminContentController::class, 'save'])->name('admin.content.store');
                Route::post('redirects', [AdminContentController::class, 'redirectStore'])->name('admin.content.redirects');
                Route::post('redirects/{redirect}/delete', [AdminContentController::class, 'redirectDelete'])->whereNumber('redirect')->name('admin.content.redirects.delete');
                Route::get('posts', [AdminBlogController::class, 'posts'])->name('admin.content.posts');
                Route::get('posts/new', [AdminBlogController::class, 'edit'])->name('admin.content.posts.new');
                Route::post('posts', [AdminBlogController::class, 'save'])->name('admin.content.posts.store');
                Route::post('categories', [AdminBlogController::class, 'category'])->name('admin.content.categories');
                Route::get('posts/{post}', [AdminBlogController::class, 'edit'])->where('post', '[0-9A-Za-z]{26}')->name('admin.content.posts.edit');
                Route::post('posts/{post}', [AdminBlogController::class, 'save'])->where('post', '[0-9A-Za-z]{26}')->name('admin.content.posts.update');
                Route::post('posts/{post}/{action}', [AdminBlogController::class, 'act'])->where('post', '[0-9A-Za-z]{26}')->whereIn('action', ['publish', 'unpublish'])->name('admin.content.posts.act');
                Route::post('core', [AdminContentController::class, 'core'])->name('admin.content.core');
                Route::get('messages', [Http\ContactController::class, 'inbox'])->name('admin.content.messages');
                Route::post('messages/{message}/handled', [Http\ContactController::class, 'handled'])->whereNumber('message')->name('admin.content.messages.handled');
                Route::get('testimonials', [Http\TestimonialController::class, 'review'])->name('admin.content.testimonials');
                Route::post('testimonials/{testimonial}/{decision}', [Http\TestimonialController::class, 'decide'])->whereNumber('testimonial')->whereIn('decision', ['approve', 'decline'])->name('admin.content.testimonials.decide');
                Route::get('menus', [AdminBlogController::class, 'menus'])->name('admin.content.menus');
                Route::post('menus', [AdminBlogController::class, 'saveMenu'])->name('admin.content.menus.save');
                Route::get('media', [AdminBlogController::class, 'media'])->name('admin.content.media');
                Route::post('media', [AdminBlogController::class, 'upload'])->middleware('throttle:60,10')->name('admin.content.media.upload');
                Route::get('{page}', [AdminContentController::class, 'edit'])->where('page', '[0-9A-Za-z]{26}')->name('admin.content.edit');
                Route::post('{page}', [AdminContentController::class, 'save'])->where('page', '[0-9A-Za-z]{26}')->name('admin.content.update');
                Route::post('{page}/{action}', [AdminContentController::class, 'act'])->where('page', '[0-9A-Za-z]{26}')->whereIn('action', ['publish', 'rollback', 'unpublish'])->name('admin.content.act');
            });
            Route::middleware(['web', 'throttle:5,60'])->post('contact', [Http\ContactController::class, 'send'])->name('contact.send');
            Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
                Route::get('app/testimonial', [Http\TestimonialController::class, 'mine'])->name('testimonial');
                Route::post('app/testimonial', [Http\TestimonialController::class, 'submit'])->middleware('throttle:10,60')->name('testimonial.submit');
                Route::post('app/testimonial/{testimonial}/withdraw', [Http\TestimonialController::class, 'withdraw'])->whereNumber('testimonial')->name('testimonial.withdraw');
            });
            Route::middleware(['web', 'noindex:always', 'auth'])->get('preview/{page}', [PublicPageController::class, 'preview'])->where('page', '[0-9A-Za-z]{26}')->name('content.preview');
            Route::middleware(['web', 'edge.cache'])->group(function (): void {
                Route::get('blog', [BlogController::class, 'index'])->name('blog');
                Route::get('blog/category/{category}', [BlogController::class, 'index'])->where('category', '[a-z0-9-]+')->name('blog.category');
                Route::get('blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('blog.post');
            });
            // Website pages and redirects: only where no app route matches.
            Route::fallback([PublicPageController::class, 'fallback'])->middleware('web')->name('content.page');
        });
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->call(fn () => Pages::publishDue() + Posts::publishDue())->name('content-publish')->everyMinute()->onOneServer());
        $this->callAfterResolving(\App\Domains\Seo\Sitemap\SitemapRegistry::class, fn ($r) => $r->register(new ContentSitemapProvider));
    }
}
