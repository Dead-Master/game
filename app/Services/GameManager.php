<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

final class GameManager
{
    private const int STARTING_HAND_P1 = 5;
    private const int STARTING_HAND_P2 = 6;

    /**
     * Явная передача экземпляра игры устраняет проблему NULL game_id.
     * Класс стал stateless, что упрощает использование в контроллерах.
     */
    public function initializeGame(Game $game): void
    {
        $player1 = $game->players()->where('side', 'player_1')->first();
        $player2 = $game->players()->where('side', 'player_2')->first();

        if ($player1) {
            $player1->update(['supply_income' => 5]);
            $this->initializePlayerDeck($player1);
            // Передаем ID игры явно при вызове
            $this->drawCardsExplicitly($player1, self::STARTING_HAND_P1, $game->id);
        }

        if ($player2) {
            $player2->update(['supply_income' => 5]);
            $this->initializePlayerDeck($player2);
            // Передаем ID игры явно при вызове
            $this->drawCardsExplicitly($player2, self::STARTING_HAND_P2, $game->id);
        }
    }

    public function generateSupplies(GamePlayer $player, int $currentTurn): void
    {
        $bonus = ($currentTurn % 2 === 0) ? 1 : 0;
        $player->supplies_current = $player->supply_income + $bonus;
    }

    public function drawCard(GamePlayer $player): void
    {
        $deck = $player->deck ?? [];
        $hand = $player->hand ?? [];

        if (empty($deck)) {
            return;
        }

        $card = array_pop($deck);
        $hand[] = $card;

        while (count($hand) > 6) {
            array_shift($hand);
        }

        $player->deck = array_values($deck);
        $player->hand = array_values($hand);
        $player->save();
    }

    public function deployCard(GamePlayer $player, array $targetCell): bool
    {
        $hand = $player->hand ?? [];

        $handIndex = null;
        foreach ($hand as $index => $card) {
            if (($card['type'] ?? null) === $targetCell['type']) {
                $handIndex = $index;
                break;
            }
        }

        if ($handIndex === null) return false;

        $basePos = $player->getPosition();
        $isAdjacent = collect($this->getAdjacentCellsForPosition($basePos))
            ->contains(fn($c) => $c['x'] === $targetCell['x'] && $c['y'] === $targetCell['y']);

        if (!$isAdjacent) return false;

        $occupied = Unit::query()
            ->where('game_id', $player->game_id)
            ->where('state', 'board')
            ->where('position_x', $targetCell['x'])
            ->where('position_y', $targetCell['y'])
            ->exists();

        if ($occupied) return false;

        $card = $hand[$handIndex];
        $cost = match($card['type']) {
            'archer' => 3,
            'berserker' => 4,
            'infantry' => 2,
            'scout' => 1
        };

        if ($player->supplies_current < $cost) return false;

        DB::transaction(function () use ($player, $card, $targetCell, $cost, $handIndex, $hand) {
            $stats = Unit::fromCardType($card['type']);
            $stats['movement_points'] = max(0, $stats['movement_points'] - 1);

            Unit::create(array_merge([
                'game_id' => $player->game_id,
                'owner_id' => $player->id,
                'type' => $card['type'],
                'state' => 'board',
            ], $stats, [
                'position_x' => $targetCell['x'],
                'position_y' => $targetCell['y']
            ]));

            array_splice($hand, $handIndex, 1);

            $player->supplies_current -= $cost;
            $player->hand = array_values($hand);
            $player->save();
        });

        return true;
    }


    public function moveUnit(GamePlayer $player, int $unitId, int $targetX, int $targetY): bool
    {
        $unit = Unit::query()
            ->where('id', $unitId)
            ->where('game_id', $player->game_id)
            ->where('owner_id', $player->id)
            ->where('state', 'board')
            ->first();

        if (!$unit) return false;
        if ($targetX < 0 || $targetX > 4 || $targetY < 0 || $targetY > 2) return false;
        if ($unit->movement_points <= 0) return false;
        if ($unit->position_x === $targetX && $unit->position_y === $targetY) return false;

        $dx = abs($unit->position_x - $targetX);
        $dy = abs($unit->position_y - $targetY);

        if (!$this->isMoveAllowedByType($unit, $dx, $dy)) {
            return false;
        }

        $targetUnit = Unit::query()
            ->where('game_id', $unit->game_id)
            ->where('state', 'board')
            ->where('position_x', $targetX)
            ->where('position_y', $targetY)
            ->first();

        if ($targetUnit && $targetUnit->owner_id === $unit->owner_id) {
            return false;
        }

        if ($targetUnit && $targetUnit->owner_id !== $unit->owner_id) {
            return $this->resolveCombat($unit, $targetUnit, $targetX, $targetY);
        }

        $distanceCost = max($dx, $dy);

        DB::transaction(function () use ($unit, $targetX, $targetY, $distanceCost) {
            $unit->position_x = $targetX;
            $unit->position_y = $targetY;
            $unit->movement_points = max(0, $unit->movement_points - $distanceCost);
            $unit->save();
        });

        return true;
    }

    private function isMoveAllowedByType(Unit $unit, int $dx, int $dy): bool
    {
        if ($dx === 0 && $dy === 0) {
            return false;
        }

        return match ($unit->type) {
            'infantry' => ($dx + $dy === 1) || ($dx === 1 && $dy === 1),
            'archer' => max($dx, $dy) <= $unit->movement_points,
            'berserker' => ($dx + $dy) === 1,
            'scout' => ($dx === 0 || $dy === 0) && (($dx + $dy) <= $unit->movement_points),
            default => false,
        };
    }

    public function endTurn(Game $game, string $currentSide): bool
    {
        $currentPlayer = $game->players()->where('side', $currentSide)->first();
        if (!$currentPlayer) return false;

        $nextSide = $currentSide === 'player_1' ? 'player_2' : 'player_1';
        $nextPlayer = $game->players()->where('side', $nextSide)->first();
        if (!$nextPlayer) return false;

        DB::transaction(function () use ($game, $nextPlayer, $nextSide) {
            if ($nextSide === 'player_1') {
                $game->round_number += 1;
            }

            $game->current_turn += 1;
            $game->save();

            $this->generateSupplies($nextPlayer, $game->current_turn);
            $this->drawCard($nextPlayer);

            $units = Unit::query()
                ->where('game_id', $game->id)
                ->where('owner_id', $nextPlayer->id)
                ->where('state', 'board')
                ->get();

            foreach ($units as $unit) {
                $baseStats = Unit::fromCardType($unit->type);
                $unit->movement_points = $baseStats['movement_points'];
                $unit->has_attacked_this_turn = false;
                $unit->has_counter_attacked_this_turn = false;
                $unit->save();
            }

            $nextPlayer->base_has_attacked_this_turn = false;
            $nextPlayer->save();
        });

        return true;
    }

    private function resolveCombat(Unit $attacker, Unit $defender, int $targetX, int $targetY): bool
    {
        DB::transaction(function () use ($attacker, $defender, $targetX, $targetY) {
            $attackerPower = $attacker->attack_power;
            $defenderPower = $defender->attack_power;

            $defender->hp -= $attackerPower;
            $attacker->movement_points = max(0, $attacker->movement_points - 1);

            $defenderDied = $defender->hp <= 0;
            if ($defenderDied) {
                $defender->state = 'graveyard';
                $defender->position_x = null;
                $defender->position_y = null;
                $defender->save();
            } else {
                $defender->save();
            }

            $canCounter = !$defenderDied
                && $defender->canCounterAttack()
                && $attacker->type !== 'berserker';

            if ($canCounter) {
                $attacker->hp -= $defenderPower;
            }

            if ($attacker->hp <= 0) {
                $attacker->state = 'graveyard';
                $attacker->position_x = null;
                $attacker->position_y = null;
                $attacker->save();
                return;
            }

            if ($defenderDied) {
                $attacker->position_x = $targetX;
                $attacker->position_y = $targetY;
            }

            $attacker->save();
        });

        return true;
    }

    private function initializePlayerDeck(GamePlayer $player): void
    {
        $deck = [];
        $composition = ['archer' => 6, 'berserker' => 8, 'scout' => 6, 'infantry' => 10];

        foreach ($composition as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $deck[] = ['type' => $type];
            }
        }

        shuffle($deck);
        $player->update(['deck' => $deck]);
    }

    public function getAdjacentCellsForPosition(array $position): array
    {
        $adjacent = [];
        $x = $position['x'];
        $y = $position['y'];

        // Соседние клетки (вверх, вниз, влево, вправо и диагонали)
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0], [-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;

            // Границы поля: X (0..4), Y (0..2)
            if ($nx >= 0 && $nx < 5 && $ny >= 0 && $ny < 3) {
                $adjacent[] = ['x' => $nx, 'y' => $ny];
            }
        }

        // Убираем штаб (саму точку), так как карты не могут быть размещены на штабе
        $adjacent = array_filter($adjacent, function($cell) use ($position) {
            return !($cell['x'] == $position['x'] && $cell['y'] == $position['y']);
        });

        return $adjacent;
    }

    private function drawCardsExplicitly(GamePlayer $player, int $count, int $gameId): void
    {
        $deck = $player->deck;
        $hand = [];

        for ($i = 0; $i < $count; $i++) {
            if (!empty($deck)) {
                $card = array_pop($deck);
                $hand[] = $card;
            }
        }

        // Обновляем JSON поля игрока
        $player->update([
            'hand' => $hand,
            'deck' => $deck,
        ]);

        // Создаем сущности юнитов для фронтенда
        foreach ($hand as $card) {
            Unit::create([
                'game_id' => $gameId,
                'owner_id' => $player->id,
                'type' => $card['type'],
                ...Unit::fromCardType($card['type']),
                'state' => 'hand',
            ]);
        }
    }

    public function attackUnit(GamePlayer $player, int $attackerUnitId, int $targetUnitId): bool
    {
        $attacker = Unit::query()
            ->where('id', $attackerUnitId)
            ->where('game_id', $player->game_id)
            ->where('owner_id', $player->id)
            ->where('state', 'board')
            ->first();

        $defender = Unit::query()
            ->where('id', $targetUnitId)
            ->where('game_id', $player->game_id)
            ->where('state', 'board')
            ->first();

        if (!$attacker || !$defender) return false;
        if ($attacker->owner_id === $defender->owner_id) return false;
        if ($attacker->has_attacked_this_turn) return false;
        if (!$this->isInAttackRange($attacker, $defender)) return false;

        return $this->resolveAttack($attacker, $defender);
    }

    private function isInAttackRange(Unit $attacker, Unit $defender): bool
    {
        if ($attacker->type === 'archer') {
            return true;
        }

        $dx = abs((int) $attacker->position_x - (int) $defender->position_x);
        $dy = abs((int) $attacker->position_y - (int) $defender->position_y);

        return max($dx, $dy) === 1;
    }

    private function resolveAttack(Unit $attacker, Unit $defender): bool
    {
        DB::transaction(function () use ($attacker, $defender) {
            $defender->hp -= $attacker->attack_power;
            $attacker->has_attacked_this_turn = true;

            $defenderDied = $defender->hp <= 0;

            if ($defenderDied) {
                $defender->state = 'graveyard';
                $defender->position_x = null;
                $defender->position_y = null;
            }

            $defender->save();

            $canDefenderCounter = !$defenderDied
                && $attacker->type !== 'archer'
                && $defender->canCounterAttack();

            if ($canDefenderCounter) {
                $attacker->hp -= $defender->attack_power;
                $defender->has_counter_attacked_this_turn = true;
                $defender->save();
            }

            if ($attacker->hp <= 0) {
                $attacker->state = 'graveyard';
                $attacker->position_x = null;
                $attacker->position_y = null;
            }

            $attacker->save();
        });

        return true;
    }

    public function attackBaseWithBase(GamePlayer $player, string $targetSide): bool
    {
        if ($player->base_has_attacked_this_turn) return false;
        if ($player->side === $targetSide) return false;

        $targetPlayer = GamePlayer::query()
            ->where('game_id', $player->game_id)
            ->where('side', $targetSide)
            ->first();

        if (!$targetPlayer) return false;

        DB::transaction(function () use ($player, $targetPlayer) {
            $targetPlayer->base_hp = max(0, $targetPlayer->base_hp - $player->base_attack);
            $player->base_has_attacked_this_turn = true;

            $targetPlayer->save();
            $player->save();

            $this->finishGameIfBaseDestroyed($targetPlayer);
        });

        return true;
    }

    public function attackUnitWithBase(GamePlayer $player, int $targetUnitId): bool
    {
        if ($player->base_has_attacked_this_turn) return false;

        $defender = Unit::query()
            ->where('id', $targetUnitId)
            ->where('game_id', $player->game_id)
            ->where('state', 'board')
            ->first();

        if (!$defender) return false;
        if ($defender->owner_id === $player->id) return false;

        DB::transaction(function () use ($player, $defender) {
            $defender->hp -= $player->base_attack;

            if ($defender->hp <= 0) {
                $defender->state = 'graveyard';
                $defender->position_x = null;
                $defender->position_y = null;
            }

            $defender->save();

            $player->base_has_attacked_this_turn = true;
            $player->save();
        });

        return true;
    }

    public function attackBaseWithUnit(GamePlayer $player, int $attackerUnitId, string $targetSide): bool
    {
        if ($player->side === $targetSide) return false;

        $attacker = Unit::query()
            ->where('id', $attackerUnitId)
            ->where('game_id', $player->game_id)
            ->where('owner_id', $player->id)
            ->where('state', 'board')
            ->first();

        $targetPlayer = GamePlayer::query()
            ->where('game_id', $player->game_id)
            ->where('side', $targetSide)
            ->first();

        if (!$attacker || !$targetPlayer) return false;
        if ($attacker->has_attacked_this_turn) return false;

        $targetBase = $targetPlayer->getPosition();

        if ($attacker->type !== 'archer') {
            $dx = abs((int) $attacker->position_x - $targetBase['x']);
            $dy = abs((int) $attacker->position_y - $targetBase['y']);
            if (max($dx, $dy) !== 1) {
                return false;
            }
        }

        DB::transaction(function () use ($attacker, $targetPlayer) {
            $targetPlayer->base_hp = max(0, $targetPlayer->base_hp - $attacker->attack_power);
            $attacker->has_attacked_this_turn = true;

            $attacker->save();
            $targetPlayer->save();

            $this->finishGameIfBaseDestroyed($targetPlayer);
        });

        return true;
    }

    private function finishGameIfBaseDestroyed(GamePlayer $targetPlayer): void
    {
        if ($targetPlayer->base_hp > 0) {
            return;
        }

        $game = Game::find($targetPlayer->game_id);
        if (!$game) {
            return;
        }

        $game->status = 'finished';
        $game->save();
    }
}
