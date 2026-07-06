<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameManager;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function store(Request $request)
    {
        // Логика создания игры
        $game = Game::create([
            'player_1_name' => $request->player_1_name,
            'player_2_name' => $request->player_2_name,
            'status' => 'active',
            'current_turn' => 1,
            'round_number' => 1,
        ]);

        // Инициализация игроков
        $gamePlayer1 = GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_1',
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        $gamePlayer2 = GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_2',
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        // Устанавливаем текущего игрока
        session(['current_player_side' => 'player_1']);

        // Возвращаем ID игры
        return response()->json(['game_id' => $game->id]);
    }

    public function showView($id)
    {
        $game = Game::with(['player1', 'player2'])->findOrFail($id);

        return view('game', compact('game'));
    }

    public function deployCard(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            // Получаем текущего игрока из сессии
            $currentPlayerSide = session('current_player_side');

            if (!$currentPlayerSide) {
                return response()->json(['success' => false, 'error' => 'No current player'], 403);
            }

            // Проверяем, что это правильный игрок
            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $currentPlayerSide)
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            // Используем сервис GameManager для размещения карты
            $gameManager = new GameManager();

            $targetCell = [
                'x' => $request->cell_x,
                'y' => $request->cell_y,
                'type' => $request->type
            ];

            $success = $gameManager->deployCard($player, $targetCell);

            if ($success) {
                return response()->json(['success' => true]);
            } else {
                return response()->json(['success' => false, 'error' => 'Failed to deploy card'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function moveUnit(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            // Получаем текущего игрока из сессии
            $currentPlayerSide = session('current_player_side');

            if (!$currentPlayerSide) {
                return response()->json(['success' => false, 'error' => 'No current player'], 403);
            }

            // Проверяем, что это правильный игрок
            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $currentPlayerSide)
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            // Используем сервис GameManager для перемещения юнита
            $gameManager = new GameManager();

            $success = $gameManager->moveUnit($player, $request->unit_id, $request->x, $request->y);

            if ($success) {
                return response()->json(['success' => true]);
            } else {
                return response()->json(['success' => false, 'error' => 'Failed to move unit'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function endTurn(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            // Получаем текущего игрока из сессии
            $currentPlayerSide = session('current_player_side');

            if (!$currentPlayerSide) {
                return response()->json(['success' => false, 'error' => 'No current player'], 403);
            }

            // Используем сервис GameManager для завершения хода
            $gameManager = new GameManager();

            $success = $gameManager->endTurn($game, $currentPlayerSide);

            if ($success) {
                return response()->json(['success' => true]);
            } else {
                return response()->json(['success' => false, 'error' => 'Failed to end turn'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
