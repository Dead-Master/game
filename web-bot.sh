BEST=$(ls -t /var/www/html/game/storage/app/ai-tuning/ai-agent-v3-best-*.json | head -n1)
echo "Using: $BEST"

AI_AGENT_V3_WEIGHTS_FILE="$BEST" \
BOT_SERVICE_TOKEN=bot-service-token \
GAME_API_BASE_URL=http://127.0.0.1 \
AI_AGENT_DEBUG_WEIGHTS=1 \
php -S 192.168.1.102:8090 bot-service/public/index.php
