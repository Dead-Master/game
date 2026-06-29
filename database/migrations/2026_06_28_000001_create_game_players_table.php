<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('side', ['player_1', 'player_2']);
            $table->integer('base_hp')->default(10);
            $table->integer('base_attack')->default(1);
            $table->integer('supply_income')->default(1);
            $table->integer('supplies_current')->default(0);
            $table->json('hand')->default('[]');
            $table->json('deck')->default('[]'); // Стартовая колода 30 карт
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
