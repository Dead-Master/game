<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GameManager;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function store(Request $request)
    {
        // Валидация входных данных
        $validated = $request->validate([
            'player_1_name' => 'required|string|max:255',
            'player_2_name' => 'required|string|max:255',
        ]);

        // Создание новой игры
        $game = Game::create([
            'status' => 'waiting',
            'current_turn' => 1,
            'round_number' => 1,
            'grid_state' => [],
            'player_1_name' => $validated['player_1_name'],
            'player_2_name' => $validated['player_2_name'],
        ]);

        // Создание игроков
        $game->players()->create([
            'side' => 'player_1',
            'user_id' => null,
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 5,
            'supplies_current' => 0,
            'hand' => [],
            'deck' => [],
        ]);

        $game->players()->create([
            'side' => 'player_2',
            'user_id' => null,
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 5,
            'supplies_current' => 0,
            'hand' => [],
            'deck' => [],
        ]);

        // Инициализация игры
        $gameManager = new GameManager();
        $gameManager->initializeGame($game);

        return redirect()->route('game.show', ['id' => $game->id]);
    }

    public function showView($id)
    {
        $game = Game::findOrFail($id);

        // Получаем данные игрока 1 (как пример)
        $player1 = $game->players()->where('side', 'player_1')->first();

        return view('game', compact('game', 'player1'));
    }

    public function deployCard(Request $request, $gameId)
    {
        // Валидация входных данных
        $validated = $request->validate([
            'type' => 'required|string',
            'x' => 'required|integer|min:0|max:4',
            'y' => 'required|integer|min:0|max:2',
        ]);

        $game = Game::findOrFail($gameId);
        $gameManager = new GameManager();

        // Получение активного игрока (предполагаем, что текущий игрок - тот, кто делает ход)
        $player = $game->players()->where('side', 'player_1')->first(); // временно для тестирования

        if (!$player) {
            return response()->json(['error' => 'Player not found'], 404);
        }

        $targetCell = [
            'type' => $validated['type'],
            'x' => $validated['x'],
            'y' => $validated['y']
        ];

        if ($gameManager->deployCard($player, $targetCell)) {
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Failed to deploy card'], 400);
    }

    public function moveUnit(Request $request, $gameId)
    {
        // Валидация входных данных
        $validated = $request->validate([
            'unit_id' => 'required|integer',
            'x' => 'required|integer|min:0|max:4',
            'y' => 'required|integer|min:0|max:2',
        ]);

        $game = Game::findOrFail($gameId);
        $gameManager = new GameManager();

        $unit = $game->units()->where('id', $validated['unit_id'])->first();

        if (!$unit) {
            return response()->json(['error' => 'Unit not found'], 404);
        }

        if ($gameManager->moveUnit($unit, $validated['x'], $validated['y'])) {
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Failed to move unit'], 400);
    }

    public function endTurn(Request $request, $gameId)
    {
        $game = Game::findOrFail($gameId);
        $gameManager = new GameManager();

        // Здесь должна быть логика окончания хода
        // Например: генерация ресурсов, сброс очков перемещения и т.д.

        $game->current_turn++;
        $game->save();

        return response()->json(['success' => true]);
    }
}
