#!/usr/bin/env php
<?php

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use BotService\Strategies\AIAgentV6BotStrategy;

try {
    $strategy = new AIAgentV6BotStrategy();
    
    echo "[✓] AIAgentV6BotStrategy instantiated successfully\n";
    echo "[✓] Strategy name: " . $strategy->name() . "\n";
    
    // Test weight loading by calling a private method via reflection
    $reflection = new ReflectionClass($strategy);
    $weightsMethod = $reflection->getMethod('weights');
    $weightsMethod->setAccessible(true);
    
    $weights = $weightsMethod->invoke($strategy);
    
    echo "[✓] Weights loaded successfully\n";
    echo "    Total weight keys: " . count($weights) . "\n";
    
    // Verify critical weights are present
    $criticalWeights = [
        'lookahead_alpha',
        'eval_base_hp_weight',
        'kill_bonus',
        'deploy_base_archer',
        'move_progress_coeff',
    ];
    
    foreach ($criticalWeights as $key) {
        if (isset($weights[$key])) {
            echo "[✓] Weight '{$key}' present: " . $weights[$key] . "\n";
        } else {
            echo "[✗] MISSING weight '{$key}'\n";
            exit(1);
        }
    }
    
    echo "\n[✓] All smoke tests passed!\n";
    exit(0);
    
} catch (Exception $e) {
    echo "[✗] ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
