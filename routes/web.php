<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LandingController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Страница входа (Landing)
Route::get('/', [LandingController::class, 'index'])->name('landing');

// История боёв
Route::get('battles', [GameController::class, 'battles'])->name('battles.index');

// Экран самой игры (отображает Blade view)
Route::get('game/{id}', [GameController::class, 'showView'])->name('game.show');

Route::get('game/{id}/replay', [GameController::class, 'showReplayPage'])->name('game.replay');

Route::post('game/create', [GameController::class, 'store'])->name('game.create');

Route::get('game/{id}/watch', [GameController::class, 'showWatchView'])->name('game.watch');
