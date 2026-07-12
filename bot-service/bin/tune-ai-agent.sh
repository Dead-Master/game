#!/usr/bin/env bash
set -euo pipefail

AI_TUNE_MODE=explore \
AI_TUNE_TRIALS=30 \
AI_TUNE_BATTLES=40 \
AI_TUNE_OPPONENT=scripted \
AI_TUNE_PARALLEL=4 \
php bot-service/bin/tune-ai-agent-v3.php
