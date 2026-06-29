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
        if (empty($player->deck)) return;

        $card = array_pop($player->deck);
        $player->hand[] = $card;

        // Ограничение руки в 6 карт (FIFO)
        while (count($player->hand) > 6) {
            array_shift($player->hand);
        }

        $player->save();
    }

    public function deployCard(GamePlayer $player, array $targetCell): bool
    {
        // Безопасный поиск индекса карты
        $handIndex = null;
        foreach ($player->hand as $index => $card) {
            if ($card['type'] === $targetCell['type']) {
                $handIndex = $index;
                break;
            }
        }

        if ($handIndex === null) return false;

        $basePos = $player->getPosition();
        $isAdjacent = collect($this->getAdjacentCellsForPosition($basePos))
            ->contains(fn($c) => $c['x'] === $targetCell['x'] && $c['y'] === $targetCell['y']);

        if (!$isAdjacent) return false;

        // Проверка занятости клетки через связи игрока (не требует game_id извне)
        $occupied = $player->units()
            ->where('state', 'board')
            ->where('position_x', $targetCell['x'])
            ->where('position_y', $targetCell['y'])
            ->exists();

        if ($occupied) return false;

        $card = $player->hand[$handIndex];
        $cost = match($card['type']) { 'archer' => 3, 'berserker' => 4, 'infantry' => 2, 'scout' => 1 };

        if ($player->supplies_current < $cost) return false;

        DB::transaction(function () use ($player, $card, $targetCell, $cost, $handIndex) {
            $stats = Unit::fromCardType($card['type']);

            // Теперь $player->game_id гарантированно существует и заполнен
            Unit::create(array_merge([
                'game_id' => $player->game_id,
                'owner_id' => $player->id,
                'state' => 'board',
            ], $stats));

            $player->supplies_current -= $cost;
            array_splice($player->hand, $handIndex, 1);
            $player->save();
        });

        return true;
    }

    public function moveUnit(Unit $unit, int $targetX, int $targetY): bool
    {
        // Проверка границ поля (5x3)
        if ($targetX < 0 || $targetX > 4 || $targetY < 0 || $targetY > 2) return false;

        // Проверка ограничений на перемещение юнита
        $dx = abs($unit->position_x - $targetX);
        $dy = abs($unit->position_y - $targetY);

        // Юнит может двигаться только по горизонтали или вертикали
        if (!($dx === 0 || $dy === 0) || ($dx + $dy > $unit->movement_points)) {
            return false;
        }

        // Проверка занятости и боевого столкновения
        $targetUnit = Unit::where('game_id', $unit->game_id)
            ->where('state', 'board')
            ->where('position_x', $targetX)
            ->where('position_y', $targetY)
            ->first();

        if ($targetUnit && $targetUnit->owner_id !== $unit->owner_id) {
            return $this->resolveCombat($unit, $targetUnit);
        } elseif (!$targetUnit) {
            DB::transaction(function () use ($unit, $targetX, $targetY) {
                $unit->position_x = $targetX;
                $unit->position_y = $targetY;
                $unit->movement_points--;
                $unit->save();
            });
            return true;
        }

        return false;
    }

    private function resolveCombat(Unit $attacker, Unit $defender): bool
    {
        DB::transaction(function () use ($attacker, $defender) {
            // Атакующий наносит урон защитнику
            $defender->hp -= $attacker->attack_power;
            $attacker->movement_points--; // Уменьшаем очки перемещения атакующего

            if ($defender->hp <= 0) {
                $defender->state = 'graveyard';
                $defender->save();
            } else {
                // Защитник отвечает, если может
                if ($defender->canCounterAttack()) {
                    $attacker->hp -= $defender->attack_power;
                    if ($attacker->hp <= 0) {
                        $attacker->state = 'graveyard';
                        $attacker->save();
                    }
                }
            }
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

    private function getAdjacentCellsForPosition(array $pos): array
    {
        $adjacent = [];
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
            $nx = $pos['x'] + $dx;
            $ny = $pos['y'] + $dy;
            if ($nx >= 0 && $nx < 5 && $ny >= 0 && $ny < 3) {
                $adjacent[] = ['x' => $nx, 'y' => $ny];
            }
        }
        return $adjacent;
    }

    /**
     * Вытягивает указанное количество карт и создает объекты юнитов в БД
     */
    private function drawCardsExplicitly(GamePlayer $player, int $count, int $gameId): void // Добавлен параметр $gameId
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
            'supplies_current' => 0,
        ]);

        // Создаем сущности юнитов для фронтенда
        foreach ($hand as $card) {
            Unit::create([
                'game_id' => $gameId,
                'owner_id' => $player->id,
                'type' => $card['type'],
                ...Unit::fromCardType($card['type']),
                'state' => 'hand', // Карты пока лежат в руке
            ]);
        }
    }
}
