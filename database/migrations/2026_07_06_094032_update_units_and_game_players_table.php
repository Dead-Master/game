<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('has_attacked_this_turn')->default(false)->after('is_active_turn');
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->boolean('base_has_attacked_this_turn')->default(false)->after('supplies_current');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('has_attacked_this_turn');
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn('base_has_attacked_this_turn');
        });
    }
};
