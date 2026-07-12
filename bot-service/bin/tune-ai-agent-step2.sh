#!/usr/bin/env bash
set -euo pipefail

# Использование: tune-ai-agent-step2.sh [путь-к-best-файлу-этапа-1]
# Без аргумента берётся самый свежий best-файл.
BEST_FILE="${1:-$(ls -t storage/app/ai-tuning/ai-agent-v3-best-*.json 2>/dev/null | head -n1)}"

if [ -z "$BEST_FILE" ] || [ ! -f "$BEST_FILE" ]; then
    echo "ERROR: best weights file not found. Run tune-ai-agent.sh (explore) first." >&2
    exit 1
fi

echo "Refining around: $BEST_FILE"

AI_TUNE_MODE=refine \
AI_TUNE_JITTER=0.15 \
AI_TUNE_TRIALS=20 \
AI_TUNE_BATTLES=60 \
AI_TUNE_OPPONENT=scripted \
AI_TUNE_PARALLEL=2 \
AI_TUNE_WEIGHTS_FILE="$BEST_FILE" \
php bot-service/bin/tune-ai-agent-v3.php
