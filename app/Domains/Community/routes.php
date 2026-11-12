<?php

use App\Domains\Community\Http\FeedController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/directory', [App\Domains\Community\Http\RecognitionController::class, 'directory'])->middleware('module:community')->name('directory');
    Route::get('app/badges', [App\Domains\Community\Http\RecognitionController::class, 'badges'])->middleware('module:community')->name('badges');
    Route::get('app/leaderboards', [App\Domains\Community\Http\RecognitionController::class, 'leaderboards'])->middleware('module:community')->name('leaderboards');
    Route::get('app/events', [App\Domains\Community\Http\EventController::class, 'index'])->middleware('module:events')->name('events');
    Route::post('app/events/{group}', [App\Domains\Community\Http\EventController::class, 'store'])->middleware('module:events')->name('events.store');
    Route::get('app/events/{event}', [App\Domains\Community\Http\EventController::class, 'show'])->whereNumber('event')->middleware('module:events')->name('events.show');
    Route::get('app/events/{event}.ics', [App\Domains\Community\Http\EventController::class, 'ics'])->whereNumber('event')->middleware('module:events')->name('events.ics');
    Route::get('app/events/{event}/qr.svg', [App\Domains\Community\Http\EventController::class, 'qr'])->whereNumber('event')->middleware('module:events')->name('events.qr');
    Route::post('app/events/{event}/rsvp', [App\Domains\Community\Http\EventController::class, 'rsvp'])->whereNumber('event')->middleware('throttle:30,10')->middleware('module:events')->name('events.rsvp');
    Route::post('app/events/{event}/update', [App\Domains\Community\Http\EventController::class, 'update'])->whereNumber('event')->middleware('module:events')->name('events.update');
    Route::post('app/events/{event}/cancel', [App\Domains\Community\Http\EventController::class, 'cancel'])->whereNumber('event')->middleware('module:events')->name('events.cancel');
    Route::post('app/events/{event}/notes', [App\Domains\Community\Http\EventController::class, 'notes'])->whereNumber('event')->middleware('module:events')->name('events.notes');
    Route::get('app/events/{event}/check-in/{code}', [App\Domains\Community\Http\EventController::class, 'checkIn'])->whereNumber('event')->where('code', '[a-z0-9]{12}')->middleware('module:events')->name('events.check-in');
    Route::get('app/feed', [FeedController::class, 'index'])->middleware('module:community')->name('feed');
    Route::get('app/feed/{group}', [FeedController::class, 'index'])->middleware('module:community')->name('feed.group');
    Route::get('app/feed/{group}/more', [FeedController::class, 'more'])->middleware('module:community')->name('feed.more');
    Route::post('app/feed/{group}/posts', [FeedController::class, 'store'])->middleware('throttle:20,10')->middleware('module:community')->name('feed.post');
    Route::post('app/feed/posts/{post}/comments', [FeedController::class, 'comment'])->whereNumber('post')->middleware('throttle:60,10')->middleware('module:community')->name('feed.comment');
    Route::post('app/feed/posts/{post}/react', [FeedController::class, 'react'])->whereNumber('post')->middleware('module:community')->name('feed.react');
    Route::post('app/feed/posts/{post}/pin', [FeedController::class, 'pin'])->whereNumber('post')->middleware('module:community')->name('feed.pin');
    Route::post('app/feed/report/{target}/{id}', [FeedController::class, 'report'])->whereIn('target', ['post', 'comment'])->whereNumber('id')->middleware('throttle:30,10')->middleware('module:community')->name('feed.report');
    Route::post('app/feed/digest', [FeedController::class, 'digest'])->middleware('module:community')->name('feed.digest');
    Route::post('app/referrals/{referral}/share-win', [FeedController::class, 'shareWin'])->middleware('module:community')->name('referrals.share-win');
    Route::get('app/group/{group}/moderation', [FeedController::class, 'moderation'])->middleware('module:community')->name('group.moderation');
    Route::post('app/feed/moderate/{target}/{id}', [FeedController::class, 'moderate'])->whereIn('target', ['post', 'comment'])->whereNumber('id')->middleware('module:community')->name('feed.moderate');
});
