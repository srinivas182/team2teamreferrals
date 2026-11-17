<?php

use Illuminate\Support\Facades\Route;

// The home page: real figures, labelled examples, and a no-sign-up simulation of sending a referral.
Route::get('/', App\Domains\Content\Http\HomeController::class)->middleware('edge.cache')->name('home');
