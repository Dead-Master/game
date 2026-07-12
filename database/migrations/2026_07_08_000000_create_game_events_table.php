<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedSmallInteger('turn_number');
            $table->unsignedSmallInteger('round_number');
            $table->enum('actor_side', ['player_1', 'player_2']);
            $table->string('event_type', 50);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['game_id', 'sequence']);
            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
