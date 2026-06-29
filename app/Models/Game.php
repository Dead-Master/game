<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'status',
        'current_turn',
        'round_number',
        'grid_state',
        'player_1_name', // Добавлено
        'player_2_name', // Добавлено
    ];

    protected $casts = [
        'current_turn' => 'integer',
        'round_number' => 'integer',
        'grid_state' => 'array',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function getActiveSide(string $side): GamePlayer
    {
        return $this->players()->where('side', $side)->firstOrFail();
    }
}
