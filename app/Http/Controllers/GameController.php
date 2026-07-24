<?php

namespace App\Http\Controllers;

use App\Jobs\TriggerExternalBotTurnJob;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePlayer;
use App\Models\Unit;
use App\Services\GameManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GameController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'player_1_name' => ['required', 'string', 'max:50'],
            'player_2_name' => ['required', 'string', 'max:50', 'different:player_1_name'],
            'bot_strategy' => ['nullable', 'in:codex_v1,codex_v2,focus_base,scripted,ai_agent_v3,ai_agent_v3_release'],
            'run_mode' => ['nullable', 'in:web,cli'],
        ]);

        $botStrategy = (string) ($validated['bot_strategy'] ?? 'codex_v2');
        $runMode = (string) ($validated['run_mode'] ?? 'web');

        $game = Game::create([
            'player_1_name' => $validated['player_1_name'],
            'player_2_name' => $validated['player_2_name'],
            'status' => 'active',
            'current_turn' => 1,
            'round_number' => 1,
            'bot_strategy' => $botStrategy,
        ]);

        if ($runMode === 'cli') {
            Cache::put($this->gameModeCacheKey((int) $game->id), 'cli', now()->addHours(12));
        }

        GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_1',
            'base_hp' => 25,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_1',
            'base_hp' => 25,
            'base_attack' => 1,
            'supply_income' => 1,
            'supplies_current' => 5,
            'hand' => [],
            'deck' => [],
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'side' => 'player_2',
            'base_hp' => 25,
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

    public function showReplayPage($id)
    {
        $game = Game::query()->findOrFail($id);

        return view('game-replay', [
            'gameId' => (int) $game->id,
            'player1Name' => (string) $game->player_1_name,
            'player2Name' => (string) $game->player_2_name,
        ]);
    }

    public function replayEvents($id)
    {
        $game = Game::with(['events'])->findOrFail($id);

        $events = $game->events()
            ->orderBy('sequence')
            ->get()
            ->map(function (GameEvent $event) {
                $payload = is_array($event->payload) ? $event->payload : [];

                return [
                    'sequence' => (int) $event->sequence,
                    'turn_number' => (int) $event->turn_number,
                    'round_number' => (int) $event->round_number,
                    'actor_side' => (string) $event->actor_side,
                    'event_type' => (string) $event->event_type,
                    'payload' => $payload,
                ];
            })
            ->values();

        $cardTypes = ['archer', 'berserker', 'infantry', 'scout'];
        $cardStats = [];

        foreach ($cardTypes as $cardType) {
            $stats = Unit::fromCardType($cardType);

            $cardStats[$cardType] = [
                'max_hp' => (int) ($stats['max_hp'] ?? 0),
                'hp' => (int) ($stats['hp'] ?? 0),
                'attack_power' => (int) ($stats['attack_power'] ?? 0),
                'movement_points' => (int) ($stats['movement_points'] ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'game' => [
                'id' => (int) $game->id,
                'player_1_name' => (string) $game->player_1_name,
                'player_2_name' => (string) $game->player_2_name,
                'status' => (string) $game->status,
            ],
            'events' => $events,
            'card_stats' => $cardStats,
        ]);
    }

    public function showView($id)
    {
        $game = Game::with(['players', 'units'])->findOrFail($id);

        $player1 = $game->players->firstWhere('side', 'player_1');
        $player2 = $game->players->firstWhere('side', 'player_2');
        $currentPlayerSide = $this->resolveCurrentSide($game);

        $loser = $game->players->first(fn (GamePlayer $player) => $player->base_hp <= 0);
        $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);
        $winnerName = $winnerSide === 'player_1'
            ? $game->player_1_name
            : ($winnerSide === 'player_2' ? $game->player_2_name : null);

        $unitsByCell = $game->units
            ->where('state', 'board')
            ->keyBy(fn (Unit $unit) => $unit->position_x . ':' . $unit->position_y);

        $recentEvents = $game->events()
            ->orderByDesc('sequence')
            ->limit(30)
            ->get()
            ->sortBy('sequence')
            ->values();

        $isReplay = false;

        return view('game', compact(
            'game',
            'player1',
            'player2',
            'currentPlayerSide',
            'unitsByCell',
            'winnerSide',
            'winnerName',
            'recentEvents',
            'isReplay'
        ));
    }

    public function showReplayView($id)
    {
        $game = Game::with(['players', 'units'])->findOrFail($id);

        $player1 = $game->players->firstWhere('side', 'player_1');
        $player2 = $game->players->firstWhere('side', 'player_2');
        $currentPlayerSide = $this->resolveCurrentSide($game);

        $loser = $game->players->first(fn (GamePlayer $player) => $player->base_hp <= 0);
        $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);
        $winnerName = $winnerSide === 'player_1'
            ? $game->player_1_name
            : ($winnerSide === 'player_2' ? $game->player_2_name : null);

        $unitsByCell = $game->units
            ->where('state', 'board')
            ->keyBy(fn (Unit $unit) => $unit->position_x . ':' . $unit->position_y);

        $recentEvents = $game->events()
            ->orderByDesc('sequence')
            ->limit(100)
            ->get()
            ->sortBy('sequence')
            ->values();

        $isReplay = true;

        return view('game', compact(
            'game',
            'player1',
            'player2',
            'currentPlayerSide',
            'unitsByCell',
            'winnerSide',
            'winnerName',
            'recentEvents',
            'isReplay'
        ));
    }

    public function battles()
    {
        $games = Game::query()
            ->with('players')
            ->orderByDesc('id')
            ->paginate(20);

        $games->getCollection()->transform(function (Game $game) {
            $loser = $game->players->first(fn (GamePlayer $player) => $player->base_hp <= 0);
            $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);
            $winnerName = $winnerSide === 'player_1'
                ? $game->player_1_name
                : ($winnerSide === 'player_2' ? $game->player_2_name : null);

            $game->setAttribute('winner_side', $winnerSide);
            $game->setAttribute('winner_name', $winnerName);

            return $game;
        });

        return view('battles', [
            'games' => $games,
        ]);
    }

    public function showWatchView($id)
    {
        Game::query()->findOrFail($id);

        return view('game-watch', [
            'gameId' => (int) $id,
        ]);
    }

    public function showState($id)
    {
        $game = Game::with(['players', 'units.owner'])->findOrFail($id);

        $loser = $game->players->first(function (GamePlayer $player) {
            return $player->base_hp <= 0;
        });

        $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);

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
                'winner_side' => $winnerSide,
                'winner_name' => $winnerSide === 'player_1'
                    ? $game->player_1_name
                    : ($winnerSide === 'player_2' ? $game->player_2_name : null),
            ],
            'players' => $game->players->map(function (GamePlayer $player) {
                return [
                    'id' => $player->id,
                    'side' => $player->side,
                    'base_hp' => $player->base_hp,
                    'base_attack' => $player->base_attack,
                    'base_has_attacked_this_turn' => (bool) $player->base_has_attacked_this_turn,
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
                    'owner_side' => $unit->owner?->side,
                    'type' => $unit->type,
                    'hp' => $unit->hp,
                    'max_hp' => $unit->max_hp,
                    'attack_power' => $unit->attack_power,
                    'movement_points' => $unit->movement_points,
                    'has_attacked_this_turn' => (bool) $unit->has_attacked_this_turn,
                    'has_counter_attacked_this_turn' => (bool) $unit->has_counter_attacked_this_turn,
                    'position_x' => $unit->position_x,
                    'position_y' => $unit->position_y,
                    'state' => $unit->state,
                ];
            })->values(),
            'current_player_side' => $this->resolveCurrentSide($game),
            'server_time' => now()->toIso8601String(),
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

            $handBefore = $this->normalizeHand($player->hand);

            $gameManager = new GameManager();

            $success = $gameManager->deployCard($player, [
                'x' => $validated['cell_x'],
                'y' => $validated['cell_y'],
                'type' => $validated['type'],
            ]);

            if ($success) {
                $player->refresh();
                $handAfter = $this->normalizeHand($player->hand);

                $newUnit = Unit::query()
                    ->where('game_id', (int) $game->id)
                    ->where('owner_id', (int) $player->id)
                    ->where('state', 'board')
                    ->where('position_x', (int) $validated['cell_x'])
                    ->where('position_y', (int) $validated['cell_y'])
                    ->orderByDesc('id')
                    ->first();

                GameEvent::record($game, $validated['side'], GameEvent::TYPE_DEPLOY_CARD, [
                    'unit_id' => $newUnit ? (int) $newUnit->id : null,
                    'unit_type' => $validated['type'],
                    'to' => [
                        'x' => (int) $validated['cell_x'],
                        'y' => (int) $validated['cell_y'],
                    ],
                    'unit' => $newUnit ? [
                        'id' => (int) $newUnit->id,
                        'owner_side' => (string) $validated['side'],
                        'type' => (string) $newUnit->type,
                        'hp' => (int) $newUnit->hp,
                        'max_hp' => (int) $newUnit->max_hp,
                        'attack_power' => (int) $newUnit->attack_power,
                        'movement_points' => (int) $newUnit->movement_points,
                        'has_attacked_this_turn' => (bool) $newUnit->has_attacked_this_turn,
                        'has_counter_attacked_this_turn' => (bool) $newUnit->has_counter_attacked_this_turn,
                        'position_x' => $newUnit->position_x !== null ? (int) $newUnit->position_x : null,
                        'position_y' => $newUnit->position_y !== null ? (int) $newUnit->position_y : null,
                        'state' => (string) $newUnit->state,
                    ] : null,
                    'hand_before' => $handBefore,
                    'hand_after' => $handAfter,
                ]);

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
            $moveResult = $gameManager->moveUnitWithAudit(
                $player,
                (int) $validated['unit_id'],
                (int) $validated['x'],
                (int) $validated['y']
            );

            if (is_array($moveResult)) {
                GameEvent::record($game, $validated['side'], GameEvent::TYPE_MOVE_UNIT, [
                    'unit_id' => (int) $validated['unit_id'],
                    'from' => $moveResult['from'],
                    'to' => $moveResult['to'],
                    'movement_points_before' => (int) ($moveResult['movement_points_before'] ?? 0),
                    'movement_points_after' => (int) ($moveResult['movement_points_after'] ?? 0),
                ]);

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

            $turnBefore = (int) $game->current_turn;
            $roundBefore = (int) $game->round_number;
            $nextSide = $validated['side'] === 'player_1' ? 'player_2' : 'player_1';

            $endedByPlayer = GamePlayer::query()
                ->where('game_id', (int) $game->id)
                ->where('side', $validated['side'])
                ->first();

            $nextPlayer = GamePlayer::query()
                ->where('game_id', (int) $game->id)
                ->where('side', $nextSide)
                ->first();

            $handsBefore = [
                $validated['side'] => [
                    'before' => $this->normalizeHand($endedByPlayer?->hand),
                ],
                $nextSide => [
                    'before' => $this->normalizeHand($nextPlayer?->hand),
                ],
            ];

            $gameManager = new GameManager();
            $success = $gameManager->endTurn($game, $validated['side']);

            if ($success) {
                $game->refresh();

                $endedByPlayer?->refresh();
                $nextPlayer?->refresh();

                $handsPayload = [
                    'player_1' => [
                        'before' => $handsBefore['player_1']['before'] ?? [],
                        'after' => $this->normalizeHand(
                            GamePlayer::query()->where('game_id', (int) $game->id)->where('side', 'player_1')->first()?->hand
                        ),
                    ],
                    'player_2' => [
                        'before' => $handsBefore['player_2']['before'] ?? [],
                        'after' => $this->normalizeHand(
                            GamePlayer::query()->where('game_id', (int) $game->id)->where('side', 'player_2')->first()?->hand
                        ),
                    ],
                ];

                GameEvent::record($game, $validated['side'], GameEvent::TYPE_END_TURN, [
                    'ended_by_side' => $validated['side'],
                    'next_side' => $nextSide,
                    'turn_before' => $turnBefore,
                    'turn_after' => (int) $game->current_turn,
                    'round_before' => $roundBefore,
                    'round_after' => (int) $game->round_number,
                    'hands' => $handsPayload,
                ]);

                if (
                    $validated['side'] === 'player_1'
                    && $nextSide === 'player_2'
                    && $game->status === 'active'
                    && !$this->isCliGame((int) $game->id)
                ) {
                    TriggerExternalBotTurnJob::dispatch((int) $game->id);
                }

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

    private function gameModeCacheKey(int $gameId): string
    {
        return 'game:mode:' . $gameId;
    }

    private function isCliGame(int $gameId): bool
    {
        return Cache::get($this->gameModeCacheKey($gameId)) === 'cli';
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

            $attackerBefore = $this->findUnitInGame((int) $game->id, (int) $validated['attacker_unit_id']);
            $targetBefore = $this->findUnitInGame((int) $game->id, (int) $validated['target_unit_id']);

            $attackerBeforeSnapshot = $this->unitSnapshot($attackerBefore);
            $targetBeforeSnapshot = $this->unitSnapshot($targetBefore);

            $gameManager = new GameManager();
            $success = $gameManager->attackUnit(
                $player,
                (int) $validated['attacker_unit_id'],
                (int) $validated['target_unit_id']
            );

            if ($success) {
                $game->refresh();

                $attackerAfter = $this->findUnitInGame((int) $game->id, (int) $validated['attacker_unit_id']);
                $targetAfter = $this->findUnitInGame((int) $game->id, (int) $validated['target_unit_id']);

                $attackerAfterSnapshot = $this->unitSnapshot($attackerAfter);
                $targetAfterSnapshot = $this->unitSnapshot($targetAfter);

                $targetHpBefore = (int) ($targetBeforeSnapshot['hp'] ?? 0);
                $targetHpAfter = (int) ($targetAfterSnapshot['hp'] ?? 0);

                if ($targetAfterSnapshot === null || ($targetAfterSnapshot['state'] ?? null) !== 'board') {
                    $targetHpAfter = 0;
                }

                $damage = max(0, $targetHpBefore - $targetHpAfter);
                $targetDied = $targetHpBefore > 0 && $targetHpAfter <= 0;

                GameEvent::record($game, $validated['side'], GameEvent::TYPE_ATTACK_UNIT, [
                    'attacker_unit_id' => (int) $validated['attacker_unit_id'],
                    'target_unit_id' => (int) $validated['target_unit_id'],
                    'attacker_attack_power' => (int) ($attackerBeforeSnapshot['attack_power'] ?? 0),
                    'attacker_before' => $attackerBeforeSnapshot,
                    'attacker_after' => $attackerAfterSnapshot,
                    'target_before' => $targetBeforeSnapshot,
                    'target_after' => $targetAfterSnapshot,
                    'target_hp_before' => $targetHpBefore,
                    'target_hp_after' => $targetHpAfter,
                    'damage' => $damage,
                    'target_died' => $targetDied,
                ]);

                if ($game->status === 'finished') {
                    $loser = $game->players()->where('base_hp', '<=', 0)->first();
                    $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);

                    GameEvent::record($game, $validated['side'], GameEvent::TYPE_GAME_FINISHED, [
                        'winner_side' => $winnerSide,
                        'loser_side' => $loser?->side,
                        'reason' => 'base_destroyed',
                    ]);
                }

                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to attack'], 400);
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
            $eventType = null;
            $eventPayload = null;

            if (!empty($validated['target_unit_id'])) {
                $targetBefore = $this->findUnitInGame((int) $game->id, (int) $validated['target_unit_id']);
                $targetBeforeSnapshot = $this->unitSnapshot($targetBefore);

                $success = $gameManager->attackUnitWithBase($player, (int) $validated['target_unit_id']);
                if ($success) {
                    $game->refresh();

                    $targetAfter = $this->findUnitInGame((int) $game->id, (int) $validated['target_unit_id']);
                    $targetAfterSnapshot = $this->unitSnapshot($targetAfter);

                    $targetHpBefore = (int) ($targetBeforeSnapshot['hp'] ?? 0);
                    $targetHpAfter = (int) ($targetAfterSnapshot['hp'] ?? 0);

                    if ($targetAfterSnapshot === null || ($targetAfterSnapshot['state'] ?? null) !== 'board') {
                        $targetHpAfter = 0;
                    }

                    $eventType = GameEvent::TYPE_ATTACK_WITH_BASE;
                    $eventPayload = [
                        'source_type' => 'base',
                        'target_unit_id' => (int) $validated['target_unit_id'],
                        'base_attack_power' => (int) $player->base_attack,
                        'target_before' => $targetBeforeSnapshot,
                        'target_after' => $targetAfterSnapshot,
                        'target_hp_before' => $targetHpBefore,
                        'target_hp_after' => $targetHpAfter,
                        'damage' => max(0, $targetHpBefore - $targetHpAfter),
                        'target_died' => $targetHpBefore > 0 && $targetHpAfter <= 0,
                    ];
                }
            } elseif (!empty($validated['target_side']) && !empty($validated['attacker_unit_id'])) {
                $attackerBefore = $this->findUnitInGame((int) $game->id, (int) $validated['attacker_unit_id']);
                $attackerBeforeSnapshot = $this->unitSnapshot($attackerBefore);

                $targetPlayerBefore = GamePlayer::query()
                    ->where('game_id', (int) $game->id)
                    ->where('side', $validated['target_side'])
                    ->first();

                $targetBaseBefore = $this->playerBaseSnapshot($targetPlayerBefore);

                $success = $gameManager->attackBaseWithUnit(
                    $player,
                    (int) $validated['attacker_unit_id'],
                    $validated['target_side']
                );
                if ($success) {
                    $game->refresh();

                    $attackerAfter = $this->findUnitInGame((int) $game->id, (int) $validated['attacker_unit_id']);
                    $attackerAfterSnapshot = $this->unitSnapshot($attackerAfter);

                    $targetPlayerAfter = GamePlayer::query()
                        ->where('game_id', (int) $game->id)
                        ->where('side', $validated['target_side'])
                        ->first();

                    $targetBaseAfter = $this->playerBaseSnapshot($targetPlayerAfter);

                    $baseHpBefore = (int) ($targetBaseBefore['base_hp'] ?? 0);
                    $baseHpAfter = (int) ($targetBaseAfter['base_hp'] ?? 0);

                    $eventType = GameEvent::TYPE_ATTACK_BASE;
                    $eventPayload = [
                        'source_type' => 'unit',
                        'attacker_unit_id' => (int) $validated['attacker_unit_id'],
                        'attacker_attack_power' => (int) ($attackerBeforeSnapshot['attack_power'] ?? 0),
                        'attacker_before' => $attackerBeforeSnapshot,
                        'attacker_after' => $attackerAfterSnapshot,
                        'target_side' => $validated['target_side'],
                        'target_base_before' => $targetBaseBefore,
                        'target_base_after' => $targetBaseAfter,
                        'target_base_hp_before' => $baseHpBefore,
                        'target_base_hp_after' => $baseHpAfter,
                        'damage' => max(0, $baseHpBefore - $baseHpAfter),
                    ];
                }
            } elseif (!empty($validated['target_side'])) {
                $targetPlayerBefore = GamePlayer::query()
                    ->where('game_id', (int) $game->id)
                    ->where('side', $validated['target_side'])
                    ->first();

                $targetBaseBefore = $this->playerBaseSnapshot($targetPlayerBefore);

                $success = $gameManager->attackBaseWithBase($player, $validated['target_side']);
                if ($success) {
                    $game->refresh();

                    $targetPlayerAfter = GamePlayer::query()
                        ->where('game_id', (int) $game->id)
                        ->where('side', $validated['target_side'])
                        ->first();

                    $targetBaseAfter = $this->playerBaseSnapshot($targetPlayerAfter);

                    $baseHpBefore = (int) ($targetBaseBefore['base_hp'] ?? 0);
                    $baseHpAfter = (int) ($targetBaseAfter['base_hp'] ?? 0);

                    $eventType = GameEvent::TYPE_ATTACK_BASE;
                    $eventPayload = [
                        'source_type' => 'base',
                        'base_attack_power' => (int) $player->base_attack,
                        'target_side' => $validated['target_side'],
                        'target_base_before' => $targetBaseBefore,
                        'target_base_after' => $targetBaseAfter,
                        'target_base_hp_before' => $baseHpBefore,
                        'target_base_hp_after' => $baseHpAfter,
                        'damage' => max(0, $baseHpBefore - $baseHpAfter),
                    ];
                }
            }

            if ($success) {
                if ($eventType !== null) {
                    GameEvent::record($game, $validated['side'], $eventType, $eventPayload);
                }

                $wasFinished = $game->status === 'finished';
                $game->refresh();

                if (!$wasFinished && $game->status === 'finished') {
                    $loser = $game->players()->where('base_hp', '<=', 0)->first();
                    $winnerSide = $loser?->side === 'player_1' ? 'player_2' : ($loser?->side === 'player_2' ? 'player_1' : null);

                    GameEvent::record($game, $validated['side'], GameEvent::TYPE_GAME_FINISHED, [
                        'winner_side' => $winnerSide,
                        'loser_side' => $loser?->side,
                        'reason' => 'base_destroyed',
                    ]);
                }

                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed to attack with base'], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }


    public function pendingBotTurns(Request $request)
    {
        $expectedToken = (string) config('services.bot_service.token', '');
        $providedToken = (string) $request->header('X-Bot-Service-Token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'side' => ['nullable', 'in:player_1,player_2'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $side = $validated['side'] ?? 'player_2';
        $limit = (int) ($validated['limit'] ?? 20);

        $turnModulo = $side === 'player_1' ? 1 : 0;

        $gameIds = Game::query()
            ->where('status', 'active')
            ->whereRaw('(current_turn % 2) = ?', [$turnModulo])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json([
            'success' => true,
            'side' => $side,
            'game_ids' => $gameIds,
            'count' => $gameIds->count(),
        ]);
    }

    private function findUnitInGame(int $gameId, int $unitId): ?Unit
    {
        return Unit::query()
            ->where('game_id', $gameId)
            ->where('id', $unitId)
            ->first();
    }

    /**
     * @return array<string, int|string|null>|null
     */
    private function unitSnapshot(?Unit $unit): ?array
    {
        if (!$unit) {
            return null;
        }

        return [
            'id' => (int) $unit->id,
            'owner_id' => (int) $unit->owner_id,
            'type' => (string) $unit->type,
            'state' => (string) $unit->state,
            'hp' => (int) $unit->hp,
            'max_hp' => (int) $unit->max_hp,
            'attack_power' => (int) $unit->attack_power,
            'movement_points' => (int) $unit->movement_points,
            'position_x' => $unit->position_x !== null ? (int) $unit->position_x : null,
            'position_y' => $unit->position_y !== null ? (int) $unit->position_y : null,
        ];
    }


    /**
     * @return array<string, int|string>|null
     */
    private function playerBaseSnapshot(?GamePlayer $player): ?array
    {
        if (!$player) {
            return null;
        }

        return [
            'player_id' => (int) $player->id,
            'side' => (string) $player->side,
            'base_hp' => (int) $player->base_hp,
            'base_attack' => (int) $player->base_attack,
        ];
    }

    /**
     * @param mixed $hand
     * @return array<int, array<string, string>>
     */
    private function normalizeHand(mixed $hand): array
    {
        if (!is_array($hand)) {
            return [];
        }

        $normalized = [];

        foreach ($hand as $card) {
            if (is_array($card) && isset($card['type']) && is_string($card['type'])) {
                $normalized[] = ['type' => $card['type']];
            }
        }

        return $normalized;
    }

}
