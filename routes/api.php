<?php

use App\Http\Controllers\GameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Создание новой игры
Route::post('/games', [GameController::class, 'store']);

// Получение текущего состояния игры
Route::get('/games/{id}', [GameController::class, 'showView']);

// Действия игрока (без CSRF проверки благодаря middleware)
Route::post('games/{game}/deploy-card', [GameController::class, 'deployCard']);
Route::post('games/{game}/move-unit', [GameController::class, 'moveUnit']);
Route::post('games/{game}/end-turn', [GameController::class, 'endTurn']);
