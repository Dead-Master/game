#!/usr/bin/env bash
set -euo pipefail

AI_TUNE_MODE=explore \
AI_TUNE_TRIALS=30 \
AI_TUNE_BATTLES=40 \
AI_TUNE_OPPONENT=ai_agent_v3_release \
AI_TUNE_PARALLEL=4 \
php bot-service/bin/tune-ai-agent-v3.php
