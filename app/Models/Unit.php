<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    protected $fillable = [
        'game_id',
        'owner_id',
        'type',
        'max_hp',
        'hp',
        'attack_power',
        'movement_points',
        'position_x',
        'position_y',
        'state',
        'is_active_turn',
    ];

    protected $casts = [
        'max_hp' => 'integer',
        'hp' => 'integer',
        'attack_power' => 'integer',
        'movement_points' => 'integer',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'is_active_turn' => 'boolean',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'owner_id');
    }

    public static function fromCardType(string $type): array
    {
        return match ($type) {
            'archer' => ['max_hp' => 2, 'hp' => 2, 'attack_power' => 1, 'movement_points' => 1],
            'berserker' => ['max_hp' => 9, 'hp' => 9, 'attack_power' => 4, 'movement_points' => 1],
            'infantry' => ['max_hp' => 5, 'hp' => 5, 'attack_power' => 2, 'movement_points' => 1],
            'scout' => ['max_hp' => 3, 'hp' => 3, 'attack_power' => 1, 'movement_points' => 2],
            default => throw new \InvalidArgumentException('Неизвестный тип юнита'),
        };
    }

    public function canCounterAttack(): bool
    {
        return $this->type === 'infantry' || $this->type === 'scout';
    }

    public function isMobileInAnyDirection(): bool
    {
        return $this->type === 'archer' || $this->type === 'infantry';
    }
}
