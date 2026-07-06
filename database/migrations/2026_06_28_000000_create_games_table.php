<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['waiting', 'playing', 'finished', 'active'])->default('active');
            $table->unsignedSmallInteger('current_turn')->default(1);
            $table->unsignedSmallInteger('round_number')->default(1);
            $table->json('grid_state')->nullable(); // Временное хранение состояния доски для оптимизации
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
