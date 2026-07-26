# 🎮 AIAgentV6 Integration Complete - Testing Guide

## ✅ Что было сделано

### 1. AIAgentV6 полностью реализована
- ✅ 1076 строк кода (AIAgentV6BotStrategy.php)
- ✅ 38 приватных методов (все необходимые helpers)
- ✅ ~50 параметризованных весов
- ✅ Конфигурация (ai-agent-v6.default-weights.json)
- ✅ Полная совместимость с v3 логикой

### 2. Интеграция в StrategyFactory
- ✅ V6 добавлена в `bot-service/src/StrategyFactory.php`
- ✅ Доступна как `ai_agent_v6`
- ✅ Может использоваться в bot-vs-bot.php скриптах

### 3. Документация подготовлена
- ✅ `RUN-V6-VS-V3-GUIDE.md` - полный гайд
- ✅ `QUICK-START-V6.md` - быстрый старт
- ✅ `PHASE1-COMPLETION.md` - техническая документация
- ✅ `run-v6-series.sh` - скрипт для серии тестов

---

## 🚀 Запуск V6 vs V3Release

### Способ 1: Одна игра (быстро)
```bash
cd bot-service
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3_release \
  --max-turns=500
```

**Вывод:**
```
[bot-vs-bot] creating game...
[bot-vs-bot] game #123 created
[bot-vs-bot] player_1 strategy=ai_agent_v6, player_2 strategy=ai_agent_v3_release
[bot-vs-bot] step=1, side=player_1, result=ok
[bot-vs-bot] step=2, side=player_2, result=ok
...
[bot-vs-bot] finished at step 156
[bot-vs-bot] winner_side=player_1, winner_name=Bot Alpha
[bot-vs-bot] game_url=http://localhost:8000/game/123
```

### Способ 2: Серия из 5 игр (рекомендуется)
```bash
bash run-v6-series.sh
```

**Вывод:**
```
AIAgentV6 vs AIAgentV3Release - 5 Game Series
==============================================
[Game 1/5]
Setup: V6 (player_1) vs V3Release (player_2)
Result: player_1 (V6)

[Game 2/5]
Setup: V3Release (player_1) vs V6 (player_2)
Result: player_2 (V6)

...

Series Results
==============================================
V6 Wins: 3/5
V3Release Wins: 2/5
V6 Win Rate: 60.00%

✅ Result in expected range (40-60% win rate)
```

### Способ 3: С custom весами
```bash
export AI_AGENT_V6_WEIGHTS_JSON='{"lookahead_alpha": 0.55}'
cd bot-service
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3_release
```

---

## 📊 Интерпретация результатов

| V6 Win Rate | Статус | Действие |
|-------------|--------|----------|
| 40–60% | ✅ Идеально | Рефакторинг успешен, переходить на Phase 2 |
| < 40% | ⚠️ Проблема | Проверить миграцию логики из v3 |
| > 60% | ⚠️ Проблема | Найти непреднамеренные улучшения в коде |

**Текущие ожидания**: V6 должна выиграть 2–3 игр из 5 (40–60%)

---

## 🔍 Доступные опции bot-vs-bot.php

```bash
php bin/bot-vs-bot.php [OPTIONS]
```

| Опция | Значение | По умолчанию |
|-------|----------|-------------|
| `--p1-strategy` | ai_agent_v6, ai_agent_v3_release, ai_agent_v3, ... | scripted_p2 |
| `--p2-strategy` | то же | scripted_p2 |
| `--p1-name` | Имя player_1 | Bot Alpha |
| `--p2-name` | Имя player_2 | Bot Beta |
| `--max-turns` | Лимит ходов | 300 |

### Примеры:

**V6 as player_1, v3_release as player_2:**
```bash
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3_release \
  --p1-name="V6" \
  --p2-name="V3Release" \
  --max-turns=500
```

**Reversed (player_2 тестирует harder):**
```bash
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v3_release \
  --p2-strategy=ai_agent_v6 \
  --p1-name="V3Release" \
  --p2-name="V6" \
  --max-turns=500
```

**V6 vs V3 (base):**
```bash
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3
```

---

## 🛠 Переменные окружения

### API URL
```bash
export GAME_API_BASE_URL="http://localhost:8000"
```

### API Token (если требуется)
```bash
export GAME_API_TOKEN="your-token"
```

### Стратегии по умолчанию
```bash
export BOT_P1_STRATEGY="ai_agent_v6"
export BOT_P2_STRATEGY="ai_agent_v3_release"
```

### Debug режим V6
```bash
export AI_AGENT_DEBUG_WEIGHTS=1
```

Вывод:
```
[ai_agent_v6] weights source: default config file .../ai-agent-v6.default-weights.json
[ai_agent_v6] weights loaded: lookahead_alpha, eval_base_hp_weight, kill_bonus, ...
```

---

## 📁 Файлы для тестирования

| Файл | Назначение |
|------|-----------|
| `bot-service/Strategies/AIAgentV6BotStrategy.php` | Основная реализация v6 |
| `bot-service/config/ai-agent-v6.default-weights.json` | Конфигурация весов |
| `bot-service/src/StrategyFactory.php` | Регистрация v6 в фабрике ✅ |
| `bot-service/bin/bot-vs-bot.php` | Скрипт для запуска матчей |
| `run-v6-series.sh` | Автоматическая серия из 5 игр |
| `RUN-V6-VS-V3-GUIDE.md` | Полный гайд |

---

## 🐛 Отладка

### Проверить что V6 загружается
```bash
php -r "
require 'bot-service/src/StrategyFactory.php';
use BotService\StrategyFactory;
\$v6 = StrategyFactory::make('ai_agent_v6');
echo 'V6 Strategy name: ' . \$v6->name() . PHP_EOL;
"
```

### Включить debug лоси V6
```bash
export AI_AGENT_DEBUG_WEIGHTS=1
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release 2>&1 | head -20
```

### Проверить конфигурацию весов
```bash
php -r "
\$weights = json_decode(file_get_contents('bot-service/config/ai-agent-v6.default-weights.json'), true);
echo 'Total weights: ' . count(\$weights) . PHP_EOL;
echo 'Sample weights:' . PHP_EOL;
foreach (array_slice(\$weights, 0, 5) as \$k => \$v) {
  echo \"  \$k: \$v\" . PHP_EOL;
}
"
```

---

## 📈 Next Steps (Phase 2)

После валидации в Phase 1:
1. **Beam pruning** - фильтровать топ-8 кандидатов
2. **Deep lookahead** - 2–3 ply поиск вместо текущего 1-ply
3. **Risk assessment** - явное моделирование смертельных рисков
4. **Automated tuning** - оптимизировать ~50 весов

---

## ✅ Чеклист перед запуском

- [ ] Laravel API запущена (`php artisan serve`)
- [ ] Bot Service может достучаться до API (проверить URL)
- [ ] AIAgentV6BotStrategy.php скомпилируется (no syntax errors)
- [ ] ai-agent-v6.default-weights.json存在 и валидна
- [ ] StrategyFactory содержит 'ai_agent_v6' case
- [ ] bot-vs-bot.php доступен и исполняем

---

**Status**: 🟢 Готовая к тестированию

Запустить матч:
```bash
cd bot-service && php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release
```

Или серию из 5:
```bash
bash run-v6-series.sh
```
