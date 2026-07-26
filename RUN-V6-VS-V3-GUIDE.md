# Как запустить AIAgentV6 против AIAgentV3Release

## 🚀 Способ 1: Используя bot-vs-bot.php скрипт (рекомендуемо)

### Требования:
- Laravel API должна быть запущена на `http://localhost:8000` (или измените URL)
- Bot Service должен быть доступен

### Команда для запуска одной игры:

```bash
# v6 vs v3_release
cd bot-service && php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3_release \
  --p1-name="AIAgentV6" \
  --p2-name="AIAgentV3Release" \
  --max-turns=300
```

### Параметры:
- `--p1-strategy` - стратегия player_1 (по умолчанию: scripted_p2)
- `--p2-strategy` - стратегия player_2 (по умолчанию: scripted_p2)
- `--p1-name` - имя player_1
- `--p2-name` - имя player_2
- `--max-turns` - максимум ходов (по умолчанию: 300)

### Примеры:

**V6 как player_1:**
```bash
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release --max-turns=500
```

**V6 как player_2:**
```bash
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v3_release --p2-strategy=ai_agent_v6 --max-turns=500
```

## 🎯 Способ 2: Переменные окружения

```bash
export GAME_API_BASE_URL="http://localhost:8000"
export BOT_P1_STRATEGY="ai_agent_v6"
export BOT_P2_STRATEGY="ai_agent_v3_release"

cd bot-service && php bin/bot-vs-bot.php --max-turns=500
```

## 📊 Способ 3: Запустить серию матчей (5 игр)

Создайте скрипт `run-v6-vs-v3-series.php`:

```bash
#!/bin/bash

RESULTS_FILE="v6-vs-v3-results.txt"
> $RESULTS_FILE

echo "Starting 5-game series: V6 vs V3 Release" | tee -a $RESULTS_FILE
echo "======================================" | tee -a $RESULTS_FILE

for i in {1..5}; do
    echo ""
    echo "Game $i of 5..." | tee -a $RESULTS_FILE
    
    if [ $((i % 2)) -eq 1 ]; then
        # V6 as player_1 (games 1, 3, 5)
        echo "Setup: V6 (p1) vs V3Release (p2)" | tee -a $RESULTS_FILE
        php bot-service/bin/bot-vs-bot.php \
          --p1-strategy=ai_agent_v6 \
          --p2-strategy=ai_agent_v3_release \
          --p1-name="V6" \
          --p2-name="V3Release" \
          --max-turns=500 | tee -a $RESULTS_FILE
    else
        # V3Release as player_1 (games 2, 4)
        echo "Setup: V3Release (p1) vs V6 (p2)" | tee -a $RESULTS_FILE
        php bot-service/bin/bot-vs-bot.php \
          --p1-strategy=ai_agent_v3_release \
          --p2-strategy=ai_agent_v6 \
          --p1-name="V3Release" \
          --p2-name="V6" \
          --max-turns=500 | tee -a $RESULTS_FILE
    fi
    
    sleep 2
done

echo ""
echo "Series complete! Results in $RESULTS_FILE"
```

Запуск:
```bash
chmod +x run-series.sh
./run-series.sh
```

## 🔍 Доступные стратегии в StrategyFactory

| Название | Класс | Статус |
|----------|-------|--------|
| `ai_agent_v6` | AIAgentV6BotStrategy | ✅ Новая |
| `ai_agent_v3_release` | AIAgentV3ReleaseBotStrategy | ✅ Tuned |
| `ai_agent_v3` | AIAgentV3BotStrategy | ✅ Base |
| `ai_agent_v2` | AIAgentV2BotStrategy | ✅ Legacy |
| `codex_v1` | CodexV1BotStrategy | ✅ |
| `codex_v2` | CodexV2BotStrategy | ✅ |
| `codex_v3` | CodexV3BotStrategy | ✅ |
| `focus_base` | FocusBaseBotStrategy | ✅ |
| `scripted` | ScriptedBotStrategy | ✅ |

## 💡 Что ожидается

**Целевая win rate V6 vs V3Release: 40–60%**

- Если V6 выигрывает 2–3 из 5 игр → рефакторинг успешен, поведение соответствует v3
- Если V6 выигрывает <2 игр → возможна ошибка в миграции логики
- Если V6 выигрывает >3 игр → возможно непреднамеренное улучшение (нужна проверка кода)

## 📝 Проверка вывода

После каждого хода скрипт выводит:
```
[bot-vs-bot] step=42, side=player_1, result=ok
[bot-vs-bot] step=43, side=player_2, result=ok
...
[bot-vs-bot] finished at step 124
[bot-vs-bot] winner_side=player_1, winner_name=V6
[bot-vs-bot] game_url=http://localhost:8000/game/42
```

Каждый `result=ok` означает что ход был выполнен успешно.

## 🐛 Отладка

Для включения debug логирования:
```bash
export AI_AGENT_DEBUG_WEIGHTS=1
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release
```

Вывод будет включать информацию о загрузке весов (stderr).

## 🔧 Настройка весов V6

Чтобы использовать custom weights для V6, передайте их через ENV:

```bash
export AI_AGENT_V6_WEIGHTS_JSON='{"lookahead_alpha": 0.5, "eval_base_hp_weight": 6.0}'
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release
```

Или используйте файл:
```bash
export AI_AGENT_V6_WEIGHTS_FILE="/path/to/weights.json"
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release
```

---

**Status**: ✅ V6 готова к тестированию
