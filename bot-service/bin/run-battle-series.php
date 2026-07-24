<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GameApiClient.php';
require_once __DIR__ . '/../src/BotRunner.php';
require_once __DIR__ . '/../src/StrategyFactory.php';
require_once __DIR__ . '/../Contracts/BotStrategyInterface.php';
require_once __DIR__ . '/../Strategies/AIAgentV2BotStrategy.php';
require_once __DIR__ . '/../Strategies/AIAgentV3BotStrategy.php';
require_once __DIR__ . '/../Strategies/AIAgentV3ReleaseBotStrategy.php';
require_once __DIR__ . '/../Strategies/CodexV1BotStrategy.php';
require_once __DIR__ . '/../Strategies/CodexV2BotStrategy.php';
require_once __DIR__ . '/../Strategies/CodexV3BotStrategy.php';
require_once __DIR__ . '/../Strategies/FocusBaseBotStrategy.php';
require_once __DIR__ . '/../Strategies/ScriptedBotStrategy.php';

use BotService\BotRunner;
use BotService\GameApiClient;
use BotService\StrategyFactory;

/**
 * ==========================
 * BATTLE SERIES CONFIG
 * ==========================
 * Настраивай только этот блок.
 */
$config = [
    'game_api_base_url' => 'http://192.168.1.102',
    'game_api_token' => 'bot-service-token', // если токен не нужен - поставь ''
    'battles_count' => 50,
    'max_turns_per_battle' => 300,

//focus_base_p1
//scripted_p2
//codex_v1
//codex_v2
//codex_v3
//ai_agent_v2

    'player_1' => [
        'name' => '',
        'strategy' => 'focus_base_p1',
    ],

    'player_2' => [
        'name' => '',
        'strategy' => 'codex_v1',
    ],

    // если true — будет подробный лог каждого шага
    'verbose' => false,
];

/**
 * Env override для тюнера и CI.
 */
$env = static function (string $key, mixed $default): mixed {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

$config['game_api_base_url'] = (string) $env('AI_BATTLE_BASE_URL', (string) $config['game_api_base_url']);
$config['game_api_token'] = (string) $env('AI_BATTLE_TOKEN', (string) $config['game_api_token']);
$config['battles_count'] = max(1, (int) $env('AI_BATTLES_COUNT', (string) $config['battles_count']));
$config['max_turns_per_battle'] = max(1, (int) $env('AI_MAX_TURNS', (string) $config['max_turns_per_battle']));
$config['player_1']['strategy'] = (string) $env('AI_P1_STRATEGY', (string) ($config['player_1']['strategy'] ?? ''));
$config['player_2']['strategy'] = (string) $env('AI_P2_STRATEGY', (string) ($config['player_2']['strategy'] ?? ''));
$config['player_1']['name'] = (string) $env('AI_P1_NAME', (string) ($config['player_1']['name'] ?? ''));
$config['player_2']['name'] = (string) $env('AI_P2_NAME', (string) ($config['player_2']['name'] ?? ''));
$config['verbose'] = in_array(
    strtolower((string) $env('AI_VERBOSE', $config['verbose'] ? '1' : '0')),
    ['1', 'true', 'yes'],
    true
);

$parallel = max(1, (int) $env('AI_BATTLE_PARALLEL', '1'));
$isInternalSingleBattle = in_array(
    strtolower((string) $env('AI_INTERNAL_SINGLE_BATTLE', '0')),
    ['1', 'true', 'yes'],
    true
);
$internalBattleIndex = max(1, (int) $env('AI_BATTLE_INDEX', '1'));

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
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

$baseUrl = rtrim((string) $config['game_api_base_url'], '/');
$apiToken = (string) ($config['game_api_token'] ?? '');

$battlesCount = max(1, (int) $config['battles_count']);
$maxTurns = max(1, (int) $config['max_turns_per_battle']);
$verbose = (bool) ($config['verbose'] ?? false);

$p1StrategyName = (string) ($config['player_1']['strategy'] ?? 'scripted_p1');
$p2StrategyName = (string) ($config['player_2']['strategy'] ?? 'scripted_p2');

$p1ConfiguredName = trim((string) ($config['player_1']['name'] ?? ''));
$p2ConfiguredName = trim((string) ($config['player_2']['name'] ?? ''));

$p1Name = $p1ConfiguredName !== '' ? $p1ConfiguredName : ('bot_' . $p1StrategyName);
$p2Name = $p2ConfiguredName !== '' ? $p2ConfiguredName : ('bot_' . $p2StrategyName);

$client = new GameApiClient($baseUrl, $apiToken);
$p1Runner = new BotRunner($client, StrategyFactory::make($p1StrategyName));
$p2Runner = new BotRunner($client, StrategyFactory::make($p2StrategyName));

$stats = [
    'player_1_wins' => 0,
    'player_2_wins' => 0,
    'draws' => 0,
    'errors' => 0,
];

if ($isInternalSingleBattle) {
    $battleResult = runSingleBattle(
        $internalBattleIndex,
        1,
        $baseUrl,
        $apiToken,
        $maxTurns,
        $p1Name,
        $p2Name,
        $p1Runner,
        $p2Runner,
        $client,
        $verbose
    );

    echo "__BATTLE_RESULT__ " . json_encode($battleResult, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "=== BOT BATTLE SERIES START ===\n";
echo "Battles: {$battlesCount}\n";
echo "Parallel: {$parallel}\n";
echo "P1: {$p1Name} ({$p1StrategyName})\n";
echo "P2: {$p2Name} ({$p2StrategyName})\n\n";

if ($parallel <= 1) {
    for ($battle = 1; $battle <= $battlesCount; $battle++) {
        $res = runSingleBattle(
            $battle,
            $battlesCount,
            $baseUrl,
            $apiToken,
            $maxTurns,
            $p1Name,
            $p2Name,
            $p1Runner,
            $p2Runner,
            $client,
            $verbose
        );

        if ($res['status'] === 'player_1_win') {
            $stats['player_1_wins']++;
        } elseif ($res['status'] === 'player_2_win') {
            $stats['player_2_wins']++;
        } elseif ($res['status'] === 'draw') {
            $stats['draws']++;
        } else {
            $stats['errors']++;
        }

        echo "\n";
    }
} else {
    $nextBattle = 1;
    /** @var array<int, array<string, mixed>> $workers */
    $workers = [];

    while ($nextBattle <= $battlesCount || $workers !== []) {
        while (count($workers) < $parallel && $nextBattle <= $battlesCount) {
            $battle = $nextBattle++;
            $childEnv = array_merge($_ENV, [
                'AI_INTERNAL_SINGLE_BATTLE' => '1',
                'AI_BATTLE_INDEX' => (string) $battle,
                'AI_BATTLE_PARALLEL' => '1',
                'AI_BATTLES_COUNT' => '1',
                'AI_MAX_TURNS' => (string) $maxTurns,
                'AI_P1_STRATEGY' => $p1StrategyName,
                'AI_P2_STRATEGY' => $p2StrategyName,
                'AI_P1_NAME' => $p1Name,
                'AI_P2_NAME' => $p2Name,
                'AI_BATTLE_BASE_URL' => $baseUrl,
                'AI_BATTLE_TOKEN' => $apiToken,
                'AI_VERBOSE' => $verbose ? '1' : '0',
            ]);

            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(['php', __FILE__], $descriptor, $pipes, dirname(__DIR__, 2), $childEnv);
            if (!is_resource($process)) {
                echo "[Battle {$battle}/{$battlesCount}] failed to start process\n";
                $stats['errors']++;
                continue;
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $workers[$battle] = [
                'process' => $process,
                'pipes' => $pipes,
                'stdout' => '',
                'stderr' => '',
            ];
        }

        foreach (array_keys($workers) as $battle) {
            /** @var resource $process */
            $process = $workers[$battle]['process'];
            /** @var array<int, resource> $pipes */
            $pipes = $workers[$battle]['pipes'];

            $outChunk = stream_get_contents($pipes[1]);
            $errChunk = stream_get_contents($pipes[2]);

            if (is_string($outChunk) && $outChunk !== '') {
                $workers[$battle]['stdout'] .= $outChunk;
                echo formatBattleChunk($outChunk, $battle);
                flush();
            }
            if (is_string($errChunk) && $errChunk !== '') {
                $workers[$battle]['stderr'] .= $errChunk;
                echo formatBattleChunk($errChunk, $battle, true);
                flush();
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                continue;
            }

            $workers[$battle]['stdout'] .= (string) stream_get_contents($pipes[1]);
            $workers[$battle]['stderr'] .= (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $output = (string) $workers[$battle]['stdout'] . "\n" . (string) $workers[$battle]['stderr'];

            unset($workers[$battle]);

            if ($exitCode !== 0) {
                echo "[Battle {$battle}/{$battlesCount}] child failed with code {$exitCode}\n";
                echo trim($output) . "\n";
                $stats['errors']++;
                continue;
            }

            $marker = parseBattleResultMarker($output);
            if ($marker === null) {
                echo "[Battle {$battle}/{$battlesCount}] no result marker from child\n";
                $stats['errors']++;
                continue;
            }

            if (($marker['status'] ?? '') === 'player_1_win') {
                $stats['player_1_wins']++;
            } elseif (($marker['status'] ?? '') === 'player_2_win') {
                $stats['player_2_wins']++;
            } elseif (($marker['status'] ?? '') === 'draw') {
                $stats['draws']++;
            } else {
                $stats['errors']++;
            }

            echo "[Battle {$battle}/{$battlesCount}] done: " . (string) ($marker['status'] ?? 'error') . "\n";
        }

        if ($workers !== []) {
            usleep(100000);
        }
    }
}


/**
 * @return array{status:string,game_id?:int,error?:string}
 */
function runSingleBattle(
    int $battle,
    int $battlesCount,
    string $baseUrl,
    string $apiToken,
    int $maxTurns,
    string $p1Name,
    string $p2Name,
    BotRunner $p1Runner,
    BotRunner $p2Runner,
    GameApiClient $client,
    bool $verbose
): array {
    echo "[Battle {$battle}/{$battlesCount}] creating game...\n";

    try {
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
            throw new RuntimeException('Invalid game_id');
        }

        $startedAt = microtime(true);
        $lastStep = 0;

        echo "  -> started, game_id={$gameId}\n";

        $finished = false;
        $winnerSide = null;

        for ($step = 1; $step <= $maxTurns; $step++) {
            $lastStep = $step;
            $state = $client->getState($gameId);

            if (($state['success'] ?? false) !== true) {
                throw new RuntimeException("State fetch failed for game {$gameId}");
            }

            $game = $state['game'] ?? [];
            $status = (string) ($game['status'] ?? 'finished');
            $currentSide = (string) ($state['current_player_side'] ?? '');

            if ($status !== 'active') {
                $winnerSide = $game['winner_side'] ?? null;
                $finished = true;
                break;
            }

            if (!in_array($currentSide, ['player_1', 'player_2'], true)) {
                throw new RuntimeException("Unexpected current side '{$currentSide}' in game {$gameId}");
            }

            $runner = $currentSide === 'player_1' ? $p1Runner : $p2Runner;
            $result = $runner->playTurn($gameId, $currentSide);

            if ($verbose) {
                $resultStatus = (string) ($result['status'] ?? 'unknown');
                echo "  step={$step}, side={$currentSide}, result={$resultStatus}\n";
            }
        }

        $elapsedSec = number_format(microtime(true) - $startedAt, 2);

        if (!$finished) {
            echo "  -> draw (max turns reached), game_id={$gameId}, steps={$lastStep}, time={$elapsedSec}s\n";
            return ['status' => 'draw', 'game_id' => $gameId];
        }

        if ($winnerSide === 'player_1') {
            echo "  -> winner: player_1 ({$p1Name}), game_id={$gameId}, steps={$lastStep}, time={$elapsedSec}s\n";
            return ['status' => 'player_1_win', 'game_id' => $gameId];
        }

        if ($winnerSide === 'player_2') {
            echo "  -> winner: player_2 ({$p2Name}), game_id={$gameId}, steps={$lastStep}, time={$elapsedSec}s\n";
            return ['status' => 'player_2_win', 'game_id' => $gameId];
        }

        echo "  -> draw/unknown winner, game_id={$gameId}, steps={$lastStep}, time={$elapsedSec}s\n";
        return ['status' => 'draw', 'game_id' => $gameId];
    } catch (Throwable $e) {
        echo "  -> error: " . $e->getMessage() . "\n";
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

/**
 * @return array<string, mixed>|null
 */
function parseBattleResultMarker(string $output): ?array
{
    $lines = preg_split('/\R/', $output);
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        if (!str_starts_with((string) $line, '__BATTLE_RESULT__ ')) {
            continue;
        }

        $json = substr((string) $line, strlen('__BATTLE_RESULT__ '));
        $decoded = json_decode((string) $json, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function formatBattleChunk(string $chunk, int $battle, bool $isErr = false): string
{
    $prefix = $isErr ? "[battle-{$battle}-err]" : "[battle-{$battle}]";
    $lines = preg_split('/\R/', $chunk);
    if (!is_array($lines)) {
        return $prefix . ' ' . $chunk . "\n";
    }

    $out = '';
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $out .= $prefix . ' ' . $line . "\n";
    }

    return $out;
}

echo "=== BOT BATTLE SERIES RESULT ===\n";
echo "Total battles: {$battlesCount}\n";
echo "{$p1Name} ({$p1StrategyName}) wins: {$stats['player_1_wins']}\n";
echo "{$p2Name} ({$p2StrategyName}) wins: {$stats['player_2_wins']}\n";
echo "Draws: {$stats['draws']}\n";
echo "Errors: {$stats['errors']}\n";
