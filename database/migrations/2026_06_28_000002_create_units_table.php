<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('game_players')->cascadeOnDelete();
            $table->enum('type', ['archer', 'berserker', 'infantry', 'scout']);
            $table->integer('max_hp');
            $table->integer('hp');
            $table->integer('attack_power');
            $table->integer('movement_points');
            $table->unsignedTinyInteger('position_x')->nullable(); // null если в руке/клмандовании
            $table->unsignedTinyInteger('position_y')->nullable();
            $table->enum('state', ['hand', 'board', 'graveyard'])->default('hand');
            $table->boolean('is_active_turn')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
