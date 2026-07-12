<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GameApiClient.php';
require_once __DIR__ . '/../src/BotRunner.php';
require_once __DIR__ . '/../src/StrategyFactory.php';
require_once __DIR__ . '/../Contracts/BotStrategyInterface.php';
require_once __DIR__ . '/../Strategies/ScriptedBotStrategy.php';

use BotService\BotRunner;
use BotService\GameApiClient;
use BotService\StrategyFactory;

function envOr(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function cliOption(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

/**
 * @return array<string, mixed>
 */
function rawHttpRequest(string $method, string $url, ?array $payload = null, ?string $token = null): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if ($token !== null && $token !== '') {
        $headers[] = 'X-Bot-Service-Token: ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: ' . $raw);
    }

    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

$baseUrl = rtrim((string) envOr('GAME_API_BASE_URL', 'http://192.168.1.102'), '/');
$apiToken = envOr('GAME_API_TOKEN', null);

$p1Name = (string) (cliOption('p1-name', 'Bot Alpha') ?? 'Bot Alpha');
$p2Name = (string) (cliOption('p2-name', 'Bot Beta') ?? 'Bot Beta');

$p1StrategyName = (string) (cliOption('p1-strategy', envOr('BOT_P1_STRATEGY', 'scripted_p2')) ?? 'scripted_p2');
$p2StrategyName = (string) (cliOption('p2-strategy', envOr('BOT_P2_STRATEGY', 'scripted_p2')) ?? 'scripted_p2');


$maxTurns = max(1, (int) (cliOption('max-turns', '300') ?? '300'));

echo "[bot-vs-bot] creating game...\n";

$create = rawHttpRequest('POST', $baseUrl . '/api/games', [
    'player_1_name' => $p1Name,
    'player_2_name' => $p2Name,
    'run_mode' => 'cli',
], $apiToken);

if (($create['success'] ?? false) !== true) {
    throw new RuntimeException('Failed to create game');
}

$gameId = (int) ($create['game_id'] ?? 0);
if ($gameId <= 0) {
    throw new RuntimeException('Invalid game_id from create response');
}

echo "[bot-vs-bot] game #{$gameId} created\n";

echo "5 ...\n";
sleep(1);
echo "4 ...\n";
sleep(1);
echo "3 ...\n";
sleep(1);
echo "2 ...\n";
sleep(1);
echo "1 ...\n";
sleep(1);

echo "[bot-vs-bot] player_1 strategy={$p1StrategyName}, player_2 strategy={$p2StrategyName}\n";

$client = new GameApiClient($baseUrl, $apiToken);

$p1Runner = new BotRunner($client, StrategyFactory::make($p1StrategyName));
$p2Runner = new BotRunner($client, StrategyFactory::make($p2StrategyName));

for ($step = 1; $step <= $maxTurns; $step++) {
    $state = $client->getState($gameId);

    if (($state['success'] ?? false) !== true) {
        throw new RuntimeException('Failed to fetch state');
    }

    $game = $state['game'] ?? [];
    $status = (string) ($game['status'] ?? 'finished');
    $currentSide = (string) ($state['current_player_side'] ?? '');

    if ($status !== 'active') {
        $winnerSide = (string) ($game['winner_side'] ?? 'unknown');
        $winnerName = (string) ($game['winner_name'] ?? 'unknown');

        echo "[bot-vs-bot] finished at step {$step}\n";
        echo "[bot-vs-bot] winner_side={$winnerSide}, winner_name={$winnerName}\n";
        echo "[bot-vs-bot] game_url={$baseUrl}/game/{$gameId}\n";
        exit(0);
    }

    if (!in_array($currentSide, ['player_1', 'player_2'], true)) {
        throw new RuntimeException('Unexpected current side: ' . $currentSide);
    }

    $runner = $currentSide === 'player_1' ? $p1Runner : $p2Runner;
    $result = $runner->playTurn($gameId, $currentSide);

    $resultStatus = (string) ($result['status'] ?? 'unknown');
    echo "[bot-vs-bot] step={$step}, side={$currentSide}, result={$resultStatus}\n";
}

echo "[bot-vs-bot] stopped by max-turns={$maxTurns}\n";
echo "[bot-vs-bot] game_url={$baseUrl}/game/{$gameId}\n";
exit(0);
