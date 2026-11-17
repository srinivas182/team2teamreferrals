<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Seo\Sitemap\SitemapRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Help centre and support tickets (Sprint 48a). */
final class SupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(SitemapRegistry::class, fn (SitemapRegistry $r) => $r->register(new HelpSitemapProvider));
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->call(fn () => Tickets::run())->hourlyAt(17)->name('support:tickets')->withoutOverlapping()->onOneServer();
        });
        $this->routes();
    }

    private function routes(): void
    {
        $slug = '[a-z0-9]+(?:-[a-z0-9]+)*';
        $ulid = '[0-9a-z]{26}';
        // The help centre: cached at the edge for signed-out visitors (members-only articles need signing in).
        Route::middleware(['web', 'edge.cache', 'throttle:120,1'])->group(function () use ($slug): void {
            Route::get('help', [Http\HelpController::class, 'index'])->name('help');
            Route::get('help/topic/{slug}', [Http\HelpController::class, 'category'])->where('slug', $slug)->name('help.topic');
        });
        Route::middleware(['web', 'noindex:always', 'throttle:60,1'])->get('help/search', [Http\HelpController::class, 'search'])->name('help.search');
        Route::middleware(['web', 'edge.cache', 'throttle:120,1'])->get('help/{slug}', [Http\HelpController::class, 'show'])->where('slug', $slug)->name('help.article');
        // Views and "was this helpful?" from the browser (the page is cached; no account is changed).
        Route::middleware(['web', 'noindex:always', 'throttle:30,1'])->withoutMiddleware(ValidateCsrfToken::class)->group(function () use ($slug): void {
            Route::post('help/{slug}/seen', [Http\HelpController::class, 'seen'])->where('slug', $slug)->name('help.seen');
            Route::post('help/{slug}/feedback', [Http\HelpController::class, 'feedback'])->where('slug', $slug)->name('help.feedback');
        });

        // Contacting support and following a ticket.
        Route::middleware(['web', 'noindex:always'])->group(function () use ($ulid): void {
            Route::get('support/new', [Http\TicketsController::class, 'create'])->name('support.new');
            Route::post('support', [Http\TicketsController::class, 'store'])->middleware('throttle:6,60')->name('support.store');
            Route::prefix('support/t/{ticket}')->where(['ticket' => $ulid])->middleware('throttle:60,1')->group(function (): void {
                Route::get('/', [Http\TicketsController::class, 'show'])->name('support.ticket');
                Route::post('reply', [Http\TicketsController::class, 'reply'])->middleware('throttle:20,10')->name('support.reply');
                Route::post('solve', [Http\TicketsController::class, 'solve'])->name('support.solve');
                Route::post('rate', [Http\TicketsController::class, 'rate'])->name('support.rate');
                Route::get('files/{message}/{index}', [Http\TicketsController::class, 'file'])->whereNumber(['message', 'index'])->name('support.file');
            });
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class])->prefix('app/support')->name('app.support.')->group(function () use ($ulid): void {
            Route::get('/', [Http\TicketsController::class, 'mine'])->name('index');
            Route::get('{ticket}', [Http\TicketsController::class, 'show'])->where('ticket', $ulid)->name('show');
            Route::post('{ticket}/reply', [Http\TicketsController::class, 'reply'])->where('ticket', $ulid)->middleware('throttle:20,10')->name('reply');
            Route::post('{ticket}/solve', [Http\TicketsController::class, 'solve'])->where('ticket', $ulid)->name('solve');
            Route::post('{ticket}/rate', [Http\TicketsController::class, 'rate'])->where('ticket', $ulid)->name('rate');
            Route::get('{ticket}/files/{message}/{index}', [Http\TicketsController::class, 'file'])->where('ticket', $ulid)->whereNumber(['message', 'index'])->name('file');
        });

        // Ally, the AI assistant (Sprint 48b): JSON for the chat window; never cached.
        Route::middleware(['web', 'noindex:always', 'module:ally'])->prefix('ally')->name('ally.')->group(function (): void {
            Route::get('state', [Http\AllyController::class, 'state'])->middleware('throttle:60,1')->name('state');
            Route::post('ask', [Http\AllyController::class, 'ask'])->middleware('throttle:20,1')->name('ask');
            Route::post('feedback', [Http\AllyController::class, 'feedback'])->middleware('throttle:60,1')->name('feedback');
            Route::post('handoff', [Http\AllyController::class, 'handOff'])->middleware('throttle:5,60')->name('handoff');
            Route::post('reset', [Http\AllyController::class, 'reset'])->middleware('throttle:20,1')->name('reset');
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:support.manage'])->prefix('admin/ally')->name('admin.ally.')->group(function () use ($ulid): void {
            Route::get('/', [Http\AdminAllyController::class, 'index'])->name('index');
            Route::get('conversations/{conversation}', [Http\AdminAllyController::class, 'conversation'])->where('conversation', $ulid)->name('conversation');
            Route::post('limits', [Http\AdminAllyController::class, 'limits'])->name('limits');
            Route::post('draft', [Http\AdminAllyController::class, 'draft'])->name('draft');
            Route::post('test', [Http\AdminAllyController::class, 'test'])->middleware('throttle:10,10')->name('test');
            Route::post('publish', [Http\AdminAllyController::class, 'publish'])->name('publish');
            Route::post('evals', [Http\AdminAllyController::class, 'saveEval'])->name('evals.store');
            Route::put('evals/{eval}', [Http\AdminAllyController::class, 'saveEval'])->whereNumber('eval')->name('evals.update');
            Route::delete('evals/{eval}', [Http\AdminAllyController::class, 'deleteEval'])->whereNumber('eval')->name('evals.delete');
        });

        // Admin → Support (tickets) and Admin → Help centre (articles).
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:support.manage'])->prefix('admin/support')->name('admin.support.')->group(function () use ($ulid): void {
            Route::get('/', [Http\AdminSupportController::class, 'index'])->name('index');
            Route::get('settings', [Http\AdminSupportController::class, 'macros'])->name('settings');
            Route::post('settings/sla', [Http\AdminSupportController::class, 'saveSla'])->name('sla');
            Route::post('macros', [Http\AdminSupportController::class, 'saveMacro'])->name('macros.store');
            Route::put('macros/{macro}', [Http\AdminSupportController::class, 'saveMacro'])->whereNumber('macro')->name('macros.update');
            Route::delete('macros/{macro}', [Http\AdminSupportController::class, 'deleteMacro'])->whereNumber('macro')->name('macros.delete');
            Route::get('{ticket}', [Http\AdminSupportController::class, 'show'])->where('ticket', $ulid)->name('show');
            Route::post('{ticket}/reply', [Http\AdminSupportController::class, 'reply'])->where('ticket', $ulid)->name('reply');
            Route::post('{ticket}/update', [Http\AdminSupportController::class, 'update'])->where('ticket', $ulid)->name('update');
            Route::get('{ticket}/files/{message}/{index}', [Http\AdminSupportController::class, 'file'])->where('ticket', $ulid)->whereNumber(['message', 'index'])->name('file');
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:content.manage'])->prefix('admin/help')->name('admin.help.')->group(function () use ($ulid): void {
            Route::get('/', [Http\AdminHelpController::class, 'index'])->name('index');
            Route::get('articles/new', [Http\AdminHelpController::class, 'edit'])->name('new');
            Route::post('articles', [Http\AdminHelpController::class, 'save'])->name('store');
            Route::post('preview', [Http\AdminHelpController::class, 'preview'])->middleware('throttle:60,1')->name('preview');
            Route::get('articles/{article}', [Http\AdminHelpController::class, 'edit'])->where('article', $ulid)->name('edit');
            Route::put('articles/{article}', [Http\AdminHelpController::class, 'save'])->where('article', $ulid)->name('update');
            Route::delete('articles/{article}', [Http\AdminHelpController::class, 'destroy'])->where('article', $ulid)->name('delete');
            Route::post('categories', [Http\AdminHelpController::class, 'saveCategory'])->name('categories.store');
            Route::put('categories/{category}', [Http\AdminHelpController::class, 'saveCategory'])->whereNumber('category')->name('categories.update');
        });
    }
}
