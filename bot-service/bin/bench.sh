php bot-service/bin/benchmark-engine.php \
  --start-parallel=1 \
  --step-parallel=2 \
  --max-parallel=1000 \
  --games-per-worker=5 \
  --p1=ai_agent_v2 \
  --p2=codex_v3 \
  --weights=/var/www/html/game/storage/app/ai-tuning/ai-agent-v2-best-20260712-034237.json
