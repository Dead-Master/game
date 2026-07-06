<?php

use App\Http\Controllers\GameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Создание новой игры
Route::post('/games', [GameController::class, 'store']);
Route::get('/games/{id}', [GameController::class, 'showState']);

Route::post('games/{game}/deploy-card', [GameController::class, 'deployCard']);
Route::post('games/{game}/move-unit', [GameController::class, 'moveUnit']);
Route::post('games/{game}/attack-unit', [GameController::class, 'attackUnit']);
Route::post('games/{game}/attack-base', [GameController::class, 'attackWithBase']);
Route::post('games/{game}/end-turn', [GameController::class, 'endTurn']);
