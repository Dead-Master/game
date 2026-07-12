<?php

declare(strict_types=1);

/**
 * Benchmark game engine throughput by parallel battle execution.
 *
 * Example:
 * php bot-service/bin/benchmark-engine.php \
 *   --max-parallel=8 \
 *   --games-per-worker=5 \
 *   --p1=ai_agent_v2 \
 *   --p2=codex_v3 \
 *   --weights=/var/www/html/game/storage/app/ai-tuning/ai-agent-v2-best-20260712-034237.json
 */

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/bot-service/bin/run-battle-series.php';

$args = parseArgs($argv);

$maxParallel = max(1, (int) ($args['max-parallel'] ?? 999));
$startParallel = (int) ($args['start-parallel'] ?? 1);
$stepParallel = (int) ($args['step-parallel'] ?? 1);
$gamesPerWorker = max(1, (int) ($args['games-per-worker'] ?? 5));
$maxTurns = max(1, (int) ($args['max-turns'] ?? 300));

$p1 = (string) ($args['p1'] ?? 'ai_agent_v2');
$p2 = (string) ($args['p2'] ?? 'codex_v3');
$p1Name = "bench_p1_" . $p1;
$p2Name = "bench_p2_" . $p2;
//$p1Name = (string) ($args['p1-name'] ?? 'bench_p1');
//$p2Name = (string) ($args['p2-name'] ?? 'bench_p2');

$baseUrl = (string) ($args['base-url'] ?? getenv('AI_BATTLE_BASE_URL') ?: 'http://192.168.1.102');
$token = (string) ($args['token'] ?? getenv('AI_BATTLE_TOKEN') ?: 'bot-service-token');

$weightsFile = (string) ($args['weights'] ?? '');
if ($weightsFile !== '' && !is_file($weightsFile)) {
    throw new RuntimeException("Weights file not found: {$weightsFile}");
}

echo "=== ENGINE BENCHMARK START ===\n";
echo "Runner: {$runner}\n";
echo "Base URL: {$baseUrl}\n";
echo "P1: {$p1}, P2: {$p2}\n";
echo "Start parallel: {$startParallel}\n";
echo "Step parallel: {$stepParallel}\n";
echo "Max parallel: {$maxParallel}\n";
echo "Games per worker: {$gamesPerWorker}\n";
echo "Max turns: {$maxTurns}\n";
echo "Weights: " . ($weightsFile !== '' ? $weightsFile : '[none]') . "\n\n";

$results = [];

for ($parallel = $startParallel; $parallel <= $maxParallel; $parallel=$parallel+$stepParallel) {
    $battles = $parallel * $gamesPerWorker;

    $env = array_merge($_ENV, [
        'AI_BATTLE_BASE_URL' => $baseUrl,
        'AI_BATTLE_TOKEN' => $token,
        'AI_P1_STRATEGY' => $p1,
        'AI_P2_STRATEGY' => $p2,
        'AI_P1_NAME' => $p1Name,
        'AI_P2_NAME' => $p2Name,
        'AI_BATTLES_COUNT' => (string) $battles,
        'AI_MAX_TURNS' => (string) $maxTurns,
        'AI_BATTLE_PARALLEL' => (string) $parallel,
        'AI_VERBOSE' => '0',
    ]);

    if ($weightsFile !== '') {
        $env['AI_AGENT_V2_WEIGHTS_FILE'] = $weightsFile;
    }

    echo "[parallel={$parallel}] battles={$battles} ...\n";

    $t0 = microtime(true);
    $output = runProcess(['php', $runner], $projectRoot, $env);
    $elapsed = microtime(true) - $t0;

    $metrics = parseSeriesMetrics($output);

    $secPerGame = $battles > 0 ? $elapsed / $battles : INF;
    $gamesPerSec = $elapsed > 0 ? $battles / $elapsed : 0.0;

    $results[] = [
        'parallel' => $parallel,
        'battles' => $battles,
        'elapsed_sec' => $elapsed,
        'sec_per_game' => $secPerGame,
        'games_per_sec' => $gamesPerSec,
        'errors' => $metrics['errors'],
        'p1_wins' => $metrics['p1_wins'],
        'p2_wins' => $metrics['p2_wins'],
        'draws' => $metrics['draws'],
    ];

    echo "  done: time=" . number_format($elapsed, 2) . "s"
        . ", sec/game=" . number_format($secPerGame, 3)
        . ", games/sec=" . number_format($gamesPerSec, 3)
        . ", errors={$metrics['errors']}\n\n";
}

echo "=== BENCHMARK TABLE ===\n";
printTable($results);

$best = pickBest($results);
if ($best !== null) {
    echo "\n=== RECOMMENDED PARALLEL ===\n";
    echo "parallel={$best['parallel']}, sec/game=" . number_format((float) $best['sec_per_game'], 3)
        . ", games/sec=" . number_format((float) $best['games_per_sec'], 3)
        . ", errors={$best['errors']}\n";
} else {
    echo "\nNo valid result to recommend.\n";
}

$outDir = $projectRoot . '/storage/app/ai-tuning';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$outFile = $outDir . '/engine-benchmark-' . date('Ymd-His') . '.json';
file_put_contents($outFile, json_encode([
    'generated_at' => date('c'),
    'config' => [
        'max_parallel' => $maxParallel,
        'games_per_worker' => $gamesPerWorker,
        'max_turns' => $maxTurns,
        'p1' => $p1,
        'p2' => $p2,
        'base_url' => $baseUrl,
    ],
    'results' => $results,
    'recommended' => $best,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Saved: {$outFile}\n";

/**
 * @return array<string, string>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (!str_starts_with((string) $arg, '--')) {
            continue;
        }
        $parts = explode('=', (string) $arg, 2);
        $key = ltrim($parts[0], '-');
        $val = $parts[1] ?? '1';
        $out[$key] = $val;
    }

    return $out;
}

/**
 * @param array<int, string> $command
 * @param array<string, string> $env
 */
function runProcess(array $command, string $cwd, array $env): string
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('proc_open failed.');
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $code = proc_close($process);

    $out = (string) $stdout . "\n" . (string) $stderr;
    if ($code !== 0) {
        throw new RuntimeException("Process failed with code {$code}\n{$out}");
    }

    return $out;
}

/**
 * @return array{p1_wins:int,p2_wins:int,draws:int,errors:int}
 */
function parseSeriesMetrics(string $output): array
{
    preg_match_all('/wins:\s+(\d+)/', $output, $wins);
    preg_match('/Draws:\s+(\d+)/', $output, $draws);
    preg_match('/Errors:\s+(\d+)/', $output, $errors);

    return [
        'p1_wins' => isset($wins[1][0]) ? (int) $wins[1][0] : 0,
        'p2_wins' => isset($wins[1][1]) ? (int) $wins[1][1] : 0,
        'draws' => isset($draws[1]) ? (int) $draws[1] : 0,
        'errors' => isset($errors[1]) ? (int) $errors[1] : 0,
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function printTable(array $rows): void
{
    $header = sprintf(
        "%-8s | %-7s | %-9s | %-9s | %-9s | %-6s | %-4s | %-4s | %-4s\n",
        'parallel',
        'battles',
        'time(s)',
        'sec/game',
        'game/sec',
        'errors',
        'W1',
        'W2',
        'D'
    );
    echo $header;
    echo str_repeat('-', strlen($header)) . "\n";

    foreach ($rows as $r) {
        echo sprintf(
            "%-8d | %-7d | %-9.2f | %-9.3f | %-9.3f | %-6d | %-4d | %-4d | %-4d\n",
            (int) $r['parallel'],
            (int) $r['battles'],
            (float) $r['elapsed_sec'],
            (float) $r['sec_per_game'],
            (float) $r['games_per_sec'],
            (int) $r['errors'],
            (int) $r['p1_wins'],
            (int) $r['p2_wins'],
            (int) $r['draws']
        );
    }
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function pickBest(array $rows): ?array
{
    $valid = array_values(array_filter($rows, fn (array $r): bool => (int) $r['errors'] === 0));
    if ($valid === []) {
        return null;
    }

    usort($valid, fn (array $a, array $b): int => ($a['sec_per_game'] <=> $b['sec_per_game']));
    return $valid[0];
}
