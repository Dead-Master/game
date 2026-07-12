#!/usr/bin/env bash
set -euo pipefail

#WEIGHTS_FILE="${1:-/var/www/html/game/storage/app/ai-tuning/ai-agent-v3-best-20260712-101234.json}"
#WEIGHTS_FILE="${1:-/var/www/html/game/bot-service/config/ai-agent-v3.default-weights.json}"

WEIGHTS_FILE="${1:-bot-service/config/ai-agent-v3.default-weights.json}"
BATTLES="${2:-50}"

if [ ! -f "$WEIGHTS_FILE" ]; then
    echo "ERROR: weights file not found: $WEIGHTS_FILE" >&2
    exit 1
fi

echo "### Series 1: ai_agent_v3 (P1) vs scripted (P2)"
AI_BATTLES_COUNT="$BATTLES" \
AI_P1_STRATEGY=ai_agent_v3 \
AI_P2_STRATEGY=scripted \
AI_P1_NAME=ai_agent_v3 \
AI_P2_NAME=scripted \
AI_AGENT_DEBUG_WEIGHTS=1 \
AI_AGENT_V3_WEIGHTS_FILE="$WEIGHTS_FILE" \
php bot-service/bin/run-battle-series.php

echo ""
echo "### Series 2 (mirror): scripted (P1) vs ai_agent_v3 (P2)"
AI_BATTLES_COUNT="$BATTLES" \
AI_P1_STRATEGY=scripted \
AI_P2_STRATEGY=ai_agent_v3 \
AI_P1_NAME=scripted \
AI_P2_NAME=ai_agent_v3 \
AI_AGENT_DEBUG_WEIGHTS=1 \
AI_AGENT_V3_WEIGHTS_FILE="$WEIGHTS_FILE" \
php bot-service/bin/run-battle-series.php
