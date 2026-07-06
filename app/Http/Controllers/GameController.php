<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Unit;
use App\Services\GameManager;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'player_1_name' => ['required', 'string', 'max:50'],
            'player_2_name' => ['required', 'string', 'max:50', 'different:player_1_name'],
        ]);

        $game = Game::create([
            'player_1_name' => $validated['player_1_name'],
            'player_2_name' => $validated['player_2_name'],
            'status' => 'active',
            'current_turn' => 1,
            'round_number' => 1,
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_1',
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_2',
            'base_hp' => 10,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        $gameManager = new GameManager();
        $gameManager->initializeGame($game);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'game_id' => $game->id,
                'current_player_side' => $this->resolveCurrentSide($game),
                'redirect' => route('game.show', ['id' => $game->id]),
            ], 201);
        }

        return redirect()->route('game.show', ['id' => $game->id]);
    }

    public function showView($id)
    {
        $game = Game::with(['players', 'units'])->findOrFail($id);

        $player1 = $game->players->firstWhere('side', 'player_1');
        $player2 = $game->players->firstWhere('side', 'player_2');
        $currentPlayerSide = $this->resolveCurrentSide($game);

        $unitsByCell = $game->units
            ->where('state', 'board')
            ->keyBy(fn (Unit $unit) => $unit->position_x . ':' . $unit->position_y);

        return view('game', compact('game', 'player1', 'player2', 'currentPlayerSide', 'unitsByCell'));
    }

    public function showState($id)
    {
        $game = Game::with(['players', 'units'])->findOrFail($id);

        $loser = $game->players->first(function (GamePlayer $player) {
            return $player->base_hp <= 0;
        });

        return response()->json([
            'success' => true,
            'game' => [
                'id' => $game->id,
                'status' => $game->status,
                'current_turn' => $game->current_turn,
                'round_number' => $game->round_number,
                'player_1_name' => $game->player_1_name,
                'player_2_name' => $game->player_2_name,
                'is_finished' => $game->status === 'finished',
                'loser_side' => $loser?->side,
                'loser_name' => $loser?->side === 'player_1'
                    ? $game->player_1_name
                    : ($loser?->side === 'player_2' ? $game->player_2_name : null),
            ],
            'players' => $game->players->map(function (GamePlayer $player) {
                return [
                    'id' => $player->id,
                    'side' => $player->side,
                    'base_hp' => $player->base_hp,
                    'base_attack' => $player->base_attack,
                    'supply_income' => $player->supply_income,
                    'supplies_current' => $player->supplies_current,
                    'hand' => $player->hand,
                    'deck_count' => is_array($player->deck) ? count($player->deck) : 0,
                ];
            })->values(),
            'units' => $game->units->map(function (Unit $unit) {
                return [
                    'id' => $unit->id,
                    'owner_id' => $unit->owner_id,
                    'type' => $unit->type,
                    'hp' => $unit->hp,
                    'max_hp' => $unit->max_hp,
                    'attack_power' => $unit->attack_power,
                    'movement_points' => $unit->movement_points,
                    'position_x' => $unit->position_x,
                    'position_y' => $unit->position_y,
                    'state' => $unit->state,
                ];
            })->values(),
            'current_player_side' => $this->resolveCurrentSide($game),
        ]);
    }

    public function deployCard(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            $validated = $request->validate([
                'side' => ['required', 'in:player_1,player_2'],
                'type' => ['required', 'in:archer,berserker,infantry,scout'],
                'cell_x' => ['required', 'integer', 'between:0,4'],
                'cell_y' => ['required', 'integer', 'between:0,2'],
            ]);

            $expectedSide = $this->resolveCurrentSide($game);
            if ($validated['side'] !== $expectedSide) {
                return response()->json(['success' => false, 'error' => 'Not your turn'], 409);
            }

            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $validated['side'])
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            $gameManager = new GameManager();

            $success = $gameManager->deployCard($player, [
                'x' => $validated['cell_x'],
                'y' => $validated['cell_y'],
                'type' => $validated['type'],
            ]);

            if ($success) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to deploy card'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function moveUnit(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            $validated = $request->validate([
                'side' => ['required', 'in:player_1,player_2'],
                'unit_id' => ['required', 'integer'],
                'x' => ['required', 'integer', 'between:0,4'],
                'y' => ['required', 'integer', 'between:0,2'],
            ]);

            $expectedSide = $this->resolveCurrentSide($game);
            if ($validated['side'] !== $expectedSide) {
                return response()->json(['success' => false, 'error' => 'Not your turn'], 409);
            }

            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $validated['side'])
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            $gameManager = new GameManager();
            $success = $gameManager->moveUnit($player, $validated['unit_id'], $validated['x'], $validated['y']);

            if ($success) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to move unit'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function endTurn(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            $validated = $request->validate([
                'side' => ['required', 'in:player_1,player_2'],
            ]);

            $expectedSide = $this->resolveCurrentSide($game);
            if ($validated['side'] !== $expectedSide) {
                return response()->json(['success' => false, 'error' => 'Not your turn'], 409);
            }

            $gameManager = new GameManager();
            $success = $gameManager->endTurn($game, $validated['side']);

            if ($success) {
                $game->refresh();

                return response()->json([
                    'success' => true,
                    'current_player_side' => $this->resolveCurrentSide($game),
                ]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to end turn'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function resolveCurrentSide(Game $game): string
    {
        return ($game->current_turn % 2 === 1) ? 'player_1' : 'player_2';
    }

    public function attackUnit(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            $validated = $request->validate([
                'side' => ['required', 'in:player_1,player_2'],
                'attacker_unit_id' => ['required', 'integer'],
                'target_unit_id' => ['required', 'integer'],
            ]);

            $expectedSide = $this->resolveCurrentSide($game);
            if ($validated['side'] !== $expectedSide) {
                return response()->json(['success' => false, 'error' => 'Not your turn'], 409);
            }

            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $validated['side'])
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            $gameManager = new GameManager();
            $success = $gameManager->attackUnit(
                $player,
                $validated['attacker_unit_id'],
                $validated['target_unit_id']
            );

            return $success
                ? response()->json(['success' => true])
                : response()->json(['success' => false, 'error' => 'Failed to attack'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function attackWithBase(Request $request, $gameId)
    {
        try {
            $game = Game::findOrFail($gameId);

            $validated = $request->validate([
                'side' => ['required', 'in:player_1,player_2'],
                'target_unit_id' => ['nullable', 'integer', 'required_without:target_side'],
                'target_side' => ['nullable', 'in:player_1,player_2', 'required_without:target_unit_id'],
                'attacker_unit_id' => ['nullable', 'integer'],
            ]);

            $expectedSide = $this->resolveCurrentSide($game);
            if ($validated['side'] !== $expectedSide) {
                return response()->json(['success' => false, 'error' => 'Not your turn'], 409);
            }

            $player = GamePlayer::where('game_id', $gameId)
                ->where('side', $validated['side'])
                ->first();

            if (!$player) {
                return response()->json(['success' => false, 'error' => 'Invalid player'], 403);
            }

            $gameManager = new GameManager();
            $success = false;

            if (!empty($validated['target_unit_id'])) {
                $success = $gameManager->attackUnitWithBase($player, (int) $validated['target_unit_id']);
            } elseif (!empty($validated['target_side']) && !empty($validated['attacker_unit_id'])) {
                $success = $gameManager->attackBaseWithUnit(
                    $player,
                    (int) $validated['attacker_unit_id'],
                    $validated['target_side']
                );
            } elseif (!empty($validated['target_side'])) {
                $success = $gameManager->attackBaseWithBase($player, $validated['target_side']);
            }

            if ($success) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to attack with base'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
