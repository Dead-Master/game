<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamePlayer extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'side',
        'base_hp',
        'base_attack',
        'supply_income',
        'supplies_current',
        'hand',
        'deck',
    ];

    protected $casts = [
        'base_hp' => 'integer',
        'base_attack' => 'integer',
        'supply_income' => 'integer',
        'supplies_current' => 'integer',
        'hand' => 'array',
        'deck' => 'array',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'owner_id', 'id');
    }

    public function getPosition(): array
    {
        // Горизонтальное поле 5x3 (X: 0-4, Y: 0-2)
        return match ($this->side) {
            // Игрок 1: Левый верхний угол
            'player_1' => ['x' => 0, 'y' => 0],
            // Игрок 2: Правый нижний угол
            'player_2' => ['x' => 4, 'y' => 2],
            default => throw new \InvalidArgumentException('Неизвестная сторона'),
        };
    }

    public function getAdjacentCells(): array
    {
        $pos = $this->getPosition();
        $adjacent = [];

        // Направление: вверх, вниз, влево, вправо
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
            $nx = $pos['x'] + $dx;
            $ny = $pos['y'] + $dy;

            // Границы поля: X (0..4), Y (0..2)
            if ($nx >= 0 && $nx < 5 && $ny >= 0 && $ny < 3) {
                $adjacent[] = ['x' => $nx, 'y' => $ny];
            }
        }
        return $adjacent;
    }
}
