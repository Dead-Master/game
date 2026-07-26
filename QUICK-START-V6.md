# ⚡ Быстрый старт: V6 vs V3Release

## 🎯 Одна игра (самый быстрый способ)

```bash
cd bot-service
php bin/bot-vs-bot.php \
  --p1-strategy=ai_agent_v6 \
  --p2-strategy=ai_agent_v3_release \
  --max-turns=500
```

## 📊 Серия из 5 игр (для статистики)

```bash
bash run-v6-series.sh
```

Результат:
- `v6-vs-v3-results-*.txt` - полный лог
- Summary в консоли: V6 Wins, V3Release Wins, Win Rate %

## 🔧 С custom весами V6

```bash
export AI_AGENT_V6_WEIGHTS_JSON='{"lookahead_alpha": 0.5}'
cd bot-service
php bin/bot-vs-bot.php --p1-strategy=ai_agent_v6 --p2-strategy=ai_agent_v3_release
```

## 📋 Все доступные стратегии

```bash
ai_agent_v6           # ← Новая (AIAgentV6BotStrategy)
ai_agent_v3_release   # ← Tuned v3
ai_agent_v3           # ← Base v3
codex_v1, codex_v2, codex_v3
focus_base
scripted
```

## 💭 Что смотреть

- **Win Rate V6**: ожидается 40–60% (= успешный рефакторинг)
- **Turn time**: < 2 сек на ход (смотреть в консоли)
- **Игры должны завершаться** за 100–300 ходов

---

Готово! V6 интегрирована и готова к тестированию. ✅
