<?php

use App\Http\Controllers\ViessmannOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('viessmann')->group(function () {
    Route::get('/connect', [ViessmannOAuthController::class, 'connect'])->name('viessmann.connect');
    Route::get('/callback', [ViessmannOAuthController::class, 'callback'])->name('viessmann.callback');
});
