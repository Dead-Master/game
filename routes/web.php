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

// Экран самой игры (отображает Blade view)
Route::get('game/{id}', [GameController::class, 'showView'])->name('game.show');

// Создание новой игры
Route::post('game/create', [GameController::class, 'store'])->name('game.create');
