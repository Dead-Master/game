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
        'base_has_attacked_this_turn',
        'hand',
        'deck',
    ];

    protected $casts = [
        'base_hp' => 'integer',
        'base_attack' => 'integer',
        'supply_income' => 'integer',
        'supplies_current' => 'integer',
        'base_has_attacked_this_turn' => 'boolean',
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
        return match ($this->side) {
            'player_1' => ['x' => 0, 'y' => 0],
            'player_2' => ['x' => 4, 'y' => 2],
            default => throw new \InvalidArgumentException('Неизвестная сторона'),
        };
    }

    public function getAdjacentCells(): array
    {
        $pos = $this->getPosition();
        $adjacent = [];

        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0], [-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dx, $dy]) {
            $nx = $pos['x'] + $dx;
            $ny = $pos['y'] + $dy;

            if ($nx >= 0 && $nx < 5 && $ny >= 0 && $ny < 3) {
                $adjacent[] = ['x' => $nx, 'y' => $ny];
            }
        }

        $adjacent = array_filter($adjacent, function($cell) use ($pos) {
            return !($cell['x'] == $pos['x'] && $cell['y'] == $pos['y']);
        });

        usort($adjacent, function($a, $b) {
            if ($a['y'] != $b['y']) {
                return $a['y'] - $b['y'];
            }
            return $a['x'] - $b['x'];
        });

        return $adjacent;
    }
}
