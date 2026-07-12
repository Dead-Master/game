#!/usr/bin/env bash
set -euo pipefail

# Использование: tune-ai-agent-step3.sh [путь-к-best-файлу-этапа-2] [кол-во-боёв]
BEST_FILE="${1:-$(ls -t storage/app/ai-tuning/ai-agent-v3-best-*.json 2>/dev/null | head -n1)}"
BATTLES="${2:-50}"

if [ -z "$BEST_FILE" ] || [ ! -f "$BEST_FILE" ]; then
    echo "ERROR: best weights file not found. Run tuning first." >&2
    exit 1
fi

echo "Validating: $BEST_FILE"

bash bot-service/bin/test.sh "$BEST_FILE" "$BATTLES"
