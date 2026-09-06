<?php

use App\Http\Controllers\PlaygroundController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SnippetController;
use Illuminate\Support\Facades\Route;

Route::get('/', PlaygroundController::class)->name('home');
Route::get('/p/{snippet}', PlaygroundController::class)->name('snippet.show');

// Throttles are per-IP and deliberately generous: they exist to stop a script
// hammering the runner, not to ration normal use. The hard ceiling on load is
// RunSlots, which caps how many containers exist at once.
Route::post('/run', RunController::class)->middleware('throttle:30,1')->name('run');
Route::post('/snippets', [SnippetController::class, 'store'])->middleware('throttle:20,1')->name('snippet.store');
