<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_game_initializes_state_and_returns_payload(): void
    {
        $response = $this->postJson('/api/games', [
            'player_1_name' => 'Alice',
            'player_2_name' => 'Bob',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'current_player_side' => 'player_1',
            ])
            ->assertJsonStructure([
                'success',
                'game_id',
                'current_player_side',
                'redirect',
            ]);

        $gameId = (int) $response->json('game_id');

        $this->assertDatabaseHas('games', [
            'id' => $gameId,
            'status' => 'active',
            'current_turn' => 1,
            'round_number' => 1,
            'player_1_name' => 'Alice',
            'player_2_name' => 'Bob',
        ]);

        $this->assertDatabaseCount('game_players', 2);
        $this->assertDatabaseCount('units', 0);
    }

    public function test_show_state_returns_game_players_and_units(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $unit = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'position_x' => 1,
            'position_y' => 0,
        ]);

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'current_player_side' => 'player_1',
            ]);

        $this->assertSame($game->id, (int) $response->json('game.id'));
        $this->assertCount(2, $response->json('players'));
        $this->assertTrue(collect($response->json('units'))->contains(fn (array $u) => (int) $u['id'] === $unit->id));
    }

    public function test_deploy_card_successfully_places_unit_on_board(): void
    {
        [$game, $player1] = $this->createGameWithPlayers();

        $player1->update([
            'hand' => [['type' => 'infantry']],
            'supplies_current' => 10,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/deploy-card", [
            'side' => 'player_1',
            'type' => 'infantry',
            'cell_x' => 1,
            'cell_y' => 0,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('units', [
            'game_id' => $game->id,
            'owner_id' => $player1->id,
            'type' => 'infantry',
            'state' => 'board',
            'position_x' => 1,
            'position_y' => 0,
        ]);

        $player1->refresh();
        $this->assertSame([], $player1->hand);
        $this->assertSame(8, (int) $player1->supplies_current);
    }

    public function test_deploy_card_fails_when_not_adjacent_to_base(): void
    {
        [$game, $player1] = $this->createGameWithPlayers();

        $player1->update([
            'hand' => [['type' => 'scout']],
            'supplies_current' => 10,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/deploy-card", [
            'side' => 'player_1',
            'type' => 'scout',
            'cell_x' => 4,
            'cell_y' => 2,
        ]);

        $response->assertStatus(400)->assertJson(['success' => false]);
        $this->assertDatabaseCount('units', 0);
    }

    public function test_move_unit_successfully_to_empty_cell(): void
    {
        [$game, $player1] = $this->createGameWithPlayers();

        $unit = $this->createBoardUnit($game, $player1, [
            'type' => 'scout',
            'movement_points' => 2,
            'position_x' => 0,
            'position_y' => 1,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/move-unit", [
            'side' => 'player_1',
            'unit_id' => $unit->id,
            'x' => 0,
            'y' => 2,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $unit->refresh();
        $this->assertSame(0, (int) $unit->position_x);
        $this->assertSame(2, (int) $unit->position_y);
        $this->assertSame(1, (int) $unit->movement_points);
    }

    public function test_move_unit_fails_when_target_cell_is_occupied(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $mover = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'movement_points' => 1,
            'position_x' => 0,
            'position_y' => 1,
        ]);

        $this->createBoardUnit($game, $player2, [
            'type' => 'scout',
            'position_x' => 1,
            'position_y' => 1,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/move-unit", [
            'side' => 'player_1',
            'unit_id' => $mover->id,
            'x' => 1,
            'y' => 1,
        ]);

        $response->assertStatus(400)->assertJson(['success' => false]);

        $mover->refresh();
        $this->assertSame(0, (int) $mover->position_x);
        $this->assertSame(1, (int) $mover->position_y);
    }

    public function test_attack_unit_success_sets_has_attacked_and_deals_damage(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'attack_power' => 2,
            'position_x' => 1,
            'position_y' => 1,
        ]);

        $defender = $this->createBoardUnit($game, $player2, [
            'type' => 'archer',
            'hp' => 2,
            'max_hp' => 2,
            'position_x' => 2,
            'position_y' => 1,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/attack-unit", [
            'side' => 'player_1',
            'attacker_unit_id' => $attacker->id,
            'target_unit_id' => $defender->id,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $attacker->refresh();
        $this->assertTrue((bool) $attacker->has_attacked_this_turn);

        $defender->refresh();
        $this->assertSame('graveyard', $defender->state);
    }

    public function test_attack_unit_fails_on_second_attack_same_turn(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'archer',
            'attack_power' => 1,
            'has_attacked_this_turn' => true,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $defender = $this->createBoardUnit($game, $player2, [
            'type' => 'infantry',
            'position_x' => 4,
            'position_y' => 2,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/attack-unit", [
            'side' => 'player_1',
            'attacker_unit_id' => $attacker->id,
            'target_unit_id' => $defender->id,
        ]);

        $response->assertStatus(400)->assertJson(['success' => false]);
    }

    public function test_base_can_attack_unit_once_per_turn(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $target = $this->createBoardUnit($game, $player2, [
            'type' => 'scout',
            'hp' => 3,
            'max_hp' => 3,
            'position_x' => 1,
            'position_y' => 1,
        ]);

        $first = $this->postJson("/api/games/{$game->id}/attack-base", [
            'side' => 'player_1',
            'target_unit_id' => $target->id,
        ]);

        $first->assertOk()->assertJson(['success' => true]);

        $player1->refresh();
        $this->assertTrue((bool) $player1->base_has_attacked_this_turn);

        $second = $this->postJson("/api/games/{$game->id}/attack-base", [
            'side' => 'player_1',
            'target_unit_id' => $target->id,
        ]);

        $second->assertStatus(400)->assertJson(['success' => false]);
    }

    public function test_unit_can_attack_enemy_base_and_finish_game(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $player2->update(['base_hp' => 2]);

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'attack_power' => 2,
            'position_x' => 3,
            'position_y' => 2,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/attack-base", [
            'side' => 'player_1',
            'target_side' => 'player_2',
            'attacker_unit_id' => $attacker->id,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $player2->refresh();
        $game->refresh();

        $this->assertSame(0, (int) $player2->base_hp);
        $this->assertSame('finished', $game->status);
    }

    public function test_base_can_attack_enemy_base_and_finish_game(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $player1->update(['base_attack' => 3]);
        $player2->update(['base_hp' => 3]);

        $response = $this->postJson("/api/games/{$game->id}/attack-base", [
            'side' => 'player_1',
            'target_side' => 'player_2',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $player2->refresh();
        $game->refresh();

        $this->assertSame(0, (int) $player2->base_hp);
        $this->assertSame('finished', $game->status);
    }

    public function test_end_turn_switches_side_and_resets_flags_for_next_player(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $player2->update(['base_has_attacked_this_turn' => true]);

        $unit = $this->createBoardUnit($game, $player2, [
            'type' => 'scout',
            'movement_points' => 0,
            'has_attacked_this_turn' => true,
            'has_counter_attacked_this_turn' => true,
            'position_x' => 3,
            'position_y' => 2,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/end-turn", [
            'side' => 'player_1',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'current_player_side' => 'player_2',
            ]);

        $player2->refresh();
        $unit->refresh();
        $game->refresh();

        $this->assertSame(2, (int) $game->current_turn);
        $this->assertFalse((bool) $player2->base_has_attacked_this_turn);
        $this->assertFalse((bool) $unit->has_attacked_this_turn);
        $this->assertFalse((bool) $unit->has_counter_attacked_this_turn);
        $this->assertSame(2, (int) $unit->movement_points);
    }

    public function test_action_fails_with_conflict_when_side_is_not_current_turn(): void
    {
        [$game] = $this->createGameWithPlayers();

        $response = $this->postJson("/api/games/{$game->id}/end-turn", [
            'side' => 'player_2',
        ]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'error' => 'Not your turn',
        ]);
    }

    public function test_deploy_card_returns_500_for_invalid_payload_because_controller_catches_validation_exception(): void
    {
        [$game] = $this->createGameWithPlayers();

        $response = $this->postJson("/api/games/{$game->id}/deploy-card", [
            'side' => 'player_3',
            'type' => 'mage',
            'cell_x' => 100,
            'cell_y' => -5,
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_move_unit_returns_500_for_invalid_payload_because_controller_catches_validation_exception(): void
    {
        [$game] = $this->createGameWithPlayers();

        $response = $this->postJson("/api/games/{$game->id}/move-unit", [
            'side' => 'player_1',
            'unit_id' => 'abc',
            'x' => 9,
            'y' => -1,
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_counterattack_happens_for_infantry_defender_against_non_archer(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'hp' => 5,
            'attack_power' => 1,
            'position_x' => 1,
            'position_y' => 1,
        ]);

        $defender = $this->createBoardUnit($game, $player2, [
            'type' => 'infantry',
            'hp' => 5,
            'attack_power' => 2,
            'position_x' => 2,
            'position_y' => 1,
        ]);

        $this->postJson("/api/games/{$game->id}/attack-unit", [
            'side' => 'player_1',
            'attacker_unit_id' => $attacker->id,
            'target_unit_id' => $defender->id,
        ])->assertOk()->assertJson(['success' => true]);

        $attacker->refresh();
        $defender->refresh();

        $this->assertSame(3, (int) $attacker->hp);
        $this->assertTrue((bool) $defender->has_counter_attacked_this_turn);
    }

    public function test_counterattack_does_not_happen_against_archer_attacker(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'archer',
            'hp' => 2,
            'attack_power' => 1,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $defender = $this->createBoardUnit($game, $player2, [
            'type' => 'infantry',
            'hp' => 5,
            'attack_power' => 2,
            'position_x' => 4,
            'position_y' => 2,
        ]);

        $this->postJson("/api/games/{$game->id}/attack-unit", [
            'side' => 'player_1',
            'attacker_unit_id' => $attacker->id,
            'target_unit_id' => $defender->id,
        ])->assertOk()->assertJson(['success' => true]);

        $attacker->refresh();
        $defender->refresh();

        $this->assertSame(2, (int) $attacker->hp);
        $this->assertFalse((bool) $defender->has_counter_attacked_this_turn);
    }

    public function test_berserker_cannot_counterattack_if_already_attacked_this_turn(): void
    {
        [$game, $player1, $player2] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'hp' => 5,
            'attack_power' => 1,
            'position_x' => 1,
            'position_y' => 1,
        ]);

        $defender = $this->createBoardUnit($game, $player2, [
            'type' => 'berserker',
            'hp' => 9,
            'attack_power' => 4,
            'has_attacked_this_turn' => true,
            'position_x' => 2,
            'position_y' => 1,
        ]);

        $this->postJson("/api/games/{$game->id}/attack-unit", [
            'side' => 'player_1',
            'attacker_unit_id' => $attacker->id,
            'target_unit_id' => $defender->id,
        ])->assertOk()->assertJson(['success' => true]);

        $attacker->refresh();
        $defender->refresh();

        $this->assertSame(5, (int) $attacker->hp);
        $this->assertFalse((bool) $defender->has_counter_attacked_this_turn);
    }

    public function test_non_archer_unit_cannot_attack_enemy_base_out_of_range(): void
    {
        [$game, $player1] = $this->createGameWithPlayers();

        $attacker = $this->createBoardUnit($game, $player1, [
            'type' => 'infantry',
            'attack_power' => 2,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $response = $this->postJson("/api/games/{$game->id}/attack-base", [
            'side' => 'player_1',
            'target_side' => 'player_2',
            'attacker_unit_id' => $attacker->id,
        ]);

        $response->assertStatus(400)->assertJson(['success' => false]);
    }

    /**
     * @return array{0: Game, 1: GamePlayer, 2: GamePlayer}
     */
    private function createGameWithPlayers(): array
    {
        $create = $this->postJson('/api/games', [
            'player_1_name' => 'Alice',
            'player_2_name' => 'Bob',
        ])->assertStatus(201);

        $gameId = (int) $create->json('game_id');

        /** @var Game $game */
        $game = Game::query()->findOrFail($gameId);

        /** @var GamePlayer $player1 */
        $player1 = GamePlayer::query()
            ->where('game_id', $gameId)
            ->where('side', 'player_1')
            ->firstOrFail();

        /** @var GamePlayer $player2 */
        $player2 = GamePlayer::query()
            ->where('game_id', $gameId)
            ->where('side', 'player_2')
            ->firstOrFail();

        return [$game, $player1, $player2];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function createBoardUnit(Game $game, GamePlayer $owner, array $override = []): Unit
    {
        $base = array_merge([
            'game_id' => $game->id,
            'owner_id' => $owner->id,
            'type' => 'infantry',
            'max_hp' => 5,
            'hp' => 5,
            'attack_power' => 2,
            'movement_points' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'state' => 'board',
            'is_active_turn' => false,
            'has_attacked_this_turn' => false,
            'has_counter_attacked_this_turn' => false,
        ], $override);

        /** @var Unit $unit */
        $unit = Unit::query()->create($base);

        return $unit;
    }
}
