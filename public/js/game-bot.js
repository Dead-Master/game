(function () {
    const BOT_SIDE = 'player_2';
    const BOT_TARGET_SIDE = 'player_1';
    const CARD_COSTS = { archer: 3, berserker: 4, infantry: 2, scout: 1 };

    let botTurnInProgress = false;

    function getBotStorageKey(gameId) {
        return `bot_enabled_game_${gameId}`;
    }

    function isEnabled(gameId) {
        return localStorage.getItem(getBotStorageKey(gameId)) === '1';
    }

    function setEnabled(gameId, enabled) {
        localStorage.setItem(getBotStorageKey(gameId), enabled ? '1' : '0');
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function getCsrfTokenSafe() {
        const el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    async function fetchGameStateRaw(gameId) {
        const response = await fetch(`/api/games/${gameId}`);
        return response.json();
    }

    function getPlayerBySide(state, side) {
        return (state.players || []).find(p => p.side === side) || null;
    }

    function getBoardUnits(state) {
        return (state.units || []).filter(u => u.state === 'board');
    }

    function getOwnUnits(state, side) {
        return getBoardUnits(state).filter(u => u.owner_side === side);
    }

    function getEnemyUnits(state, side) {
        return getBoardUnits(state).filter(u => u.owner_side !== side);
    }

    function unitAt(state, x, y) {
        return getBoardUnits(state).find(u => u.position_x === x && u.position_y === y) || null;
    }

    function cellEmpty(state, x, y) {
        return unitAt(state, x, y) === null;
    }

    function canMoveByType(unit, targetX, targetY) {
        if (unit.movement_points <= 0) return false;
        if (unit.position_x === targetX && unit.position_y === targetY) return false;
        if (targetX < 0 || targetX > 4 || targetY < 0 || targetY > 2) return false;

        const dx = Math.abs(unit.position_x - targetX);
        const dy = Math.abs(unit.position_y - targetY);

        if (unit.type === 'infantry') return (dx + dy === 1) || (dx === 1 && dy === 1);
        if (unit.type === 'archer') return Math.max(dx, dy) <= unit.movement_points;
        if (unit.type === 'berserker') return (dx + dy) === 1;
        if (unit.type === 'scout') return (dx === 0 || dy === 0) && ((dx + dy) <= unit.movement_points);

        return false;
    }

    function canAttackUnitByType(attacker, defender) {
        if (!attacker || !defender) return false;
        if (attacker.has_attacked_this_turn) return false;
        if (attacker.owner_side === defender.owner_side) return false;

        if (attacker.type === 'archer') return true;

        const dx = Math.abs(attacker.position_x - defender.position_x);
        const dy = Math.abs(attacker.position_y - defender.position_y);
        return Math.max(dx, dy) === 1;
    }

    function canAttackBase(attacker, targetSide) {
        if (!attacker || attacker.has_attacked_this_turn) return false;

        const basePos = targetSide === 'player_1' ? { x: 0, y: 0 } : { x: 4, y: 2 };
        if (attacker.type === 'archer') return true;

        const dx = Math.abs(attacker.position_x - basePos.x);
        const dy = Math.abs(attacker.position_y - basePos.y);
        return Math.max(dx, dy) === 1;
    }

    function findLowestHpEnemy(state, side) {
        const enemies = getEnemyUnits(state, side);
        if (!enemies.length) return null;

        enemies.sort((a, b) => (a.hp - b.hp) || (a.id - b.id));
        return enemies[0];
    }

    function findFrontLineTargetForUnit(state, unit, frontX) {
        const enemiesOnFrontLine = getEnemyUnits(state, BOT_SIDE)
            .filter(e => e.position_x === frontX)
            .filter(e => canAttackUnitByType(unit, e))
            .sort((a, b) => {
                if (a.hp !== b.hp) return a.hp - b.hp;
                const aDy = Math.abs(a.position_y - unit.position_y);
                const bDy = Math.abs(b.position_y - unit.position_y);
                if (aDy !== bDy) return aDy - bDy;
                return a.id - b.id;
            });

        return enemiesOnFrontLine[0] || null;
    }

    function pickPlayableCard(hand, supplies) {
        const sorted = [...(hand || [])].sort((a, b) => {
            const costA = CARD_COSTS[a.type] || 999;
            const costB = CARD_COSTS[b.type] || 999;
            return costA - costB;
        });

        return sorted.find(card => supplies >= (CARD_COSTS[card.type] || 999)) || null;
    }

    async function postApi(path, payload) {
        const response = await fetch(path, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfTokenSafe()
            },
            body: JSON.stringify(payload)
        });

        return response.json();
    }

    async function botMoveUnit(gameId, unitId, x, y) {
        const data = await postApi(`/api/games/${gameId}/move-unit`, { side: BOT_SIDE, unit_id: unitId, x, y });
        return !!data.success;
    }

    async function botAttackUnit(gameId, attackerUnitId, targetUnitId) {
        const data = await postApi(`/api/games/${gameId}/attack-unit`, {
            side: BOT_SIDE,
            attacker_unit_id: attackerUnitId,
            target_unit_id: targetUnitId
        });
        return !!data.success;
    }

    async function botAttackBaseWithUnit(gameId, attackerUnitId) {
        const data = await postApi(`/api/games/${gameId}/attack-base`, {
            side: BOT_SIDE,
            target_side: BOT_TARGET_SIDE,
            attacker_unit_id: attackerUnitId
        });
        return !!data.success;
    }

    async function botAttackWithBaseToUnit(gameId, targetUnitId) {
        const data = await postApi(`/api/games/${gameId}/attack-base`, {
            side: BOT_SIDE,
            target_unit_id: targetUnitId
        });
        return !!data.success;
    }

    async function botDeploy(gameId, type, x, y) {
        const data = await postApi(`/api/games/${gameId}/deploy-card`, {
            side: BOT_SIDE, type, cell_x: x, cell_y: y
        });
        return !!data.success;
    }

    async function botEndTurn(gameId) {
        const data = await postApi(`/api/games/${gameId}/end-turn`, { side: BOT_SIDE });
        return !!data.success;
    }

    async function botTryAttackBestForUnit(gameId, attackerId) {
        const fresh = await fetchGameStateRaw(gameId);
        const attacker = getOwnUnits(fresh, BOT_SIDE).find(u => u.id === attackerId);
        if (!attacker || attacker.has_attacked_this_turn) return false;

        const enemies = getEnemyUnits(fresh, BOT_SIDE)
            .filter(e => canAttackUnitByType(attacker, e))
            .sort((a, b) => (a.hp - b.hp) || (a.id - b.id));

        if (!enemies.length) return false;

        const ok = await botAttackUnit(gameId, attacker.id, enemies[0].id);
        if (ok) await sleep(120);
        return ok;
    }

    async function runBotTurn(gameId) {
        let state = await fetchGameStateRaw(gameId);
        if (!state.success || state.current_player_side !== BOT_SIDE) return;

        // Спец-ветка: если на (4,1) стоит не арчер
        let unitAt41 = unitAt(state, 4, 1);
        if (unitAt41 && unitAt41.owner_side === BOT_SIDE && unitAt41.type !== 'archer') {
            await botTryAttackBestForUnit(gameId, unitAt41.id);
            await sleep(120);
            state = await fetchGameStateRaw(gameId);

            const freshAt41 = getOwnUnits(state, BOT_SIDE).find(u => u.id === unitAt41.id);
            if (freshAt41 && cellEmpty(state, 3, 1) && canMoveByType(freshAt41, 3, 1)) {
                await botMoveUnit(gameId, freshAt41.id, 3, 1);
                await sleep(120);
                state = await fetchGameStateRaw(gameId);

                // после перемещения ещё раз пытается атаковать, если атака не потрачена
                const moved = getOwnUnits(state, BOT_SIDE).find(u => u.id === unitAt41.id);
                if (moved && !moved.has_attacked_this_turn) {
                    await botTryAttackBestForUnit(gameId, moved.id);
                    await sleep(120);
                    state = await fetchGameStateRaw(gameId);
                }
            }

            if (cellEmpty(state, 4, 1)) {
                const botPlayer = getPlayerBySide(state, BOT_SIDE);
                const playableCard = pickPlayableCard(botPlayer?.hand || [], botPlayer?.supplies_current || 0);

                if (playableCard) {
                    const deployed = await botDeploy(gameId, playableCard.type, 4, 1);
                    if (deployed) {
                        await sleep(120);
                        state = await fetchGameStateRaw(gameId);

                        const justDeployed = getOwnUnits(state, BOT_SIDE).find(
                            u => u.position_x === 4 && u.position_y === 1
                        );

                        if (justDeployed) {
                            await botTryAttackBestForUnit(gameId, justDeployed.id);
                            await sleep(120);
                        }
                    }
                }
            }

            await botEndTurn(gameId);
            return;
        }

        // 1) (4,1) archer -> (4,0)
        let archerAt41 = unitAt(state, 4, 1);
        if (archerAt41 && archerAt41.owner_side === BOT_SIDE && archerAt41.type === 'archer' && cellEmpty(state, 4, 0)) {
            if (canMoveByType(archerAt41, 4, 0)) {
                await botMoveUnit(gameId, archerAt41.id, 4, 0);
                await sleep(120);
                state = await fetchGameStateRaw(gameId);
            }
        }

        // 2) deploy archer at (4,1)
        const botPlayerA = getPlayerBySide(state, BOT_SIDE);
        const hasArcherInHandA = !!((botPlayerA?.hand || []).find(c => c.type === 'archer'));
        if ((botPlayerA?.supplies_current || 0) >= CARD_COSTS.archer && hasArcherInHandA && cellEmpty(state, 4, 1)) {
            await botDeploy(gameId, 'archer', 4, 1);
            await sleep(120);
            state = await fetchGameStateRaw(gameId);
        }

        // 3) unit at (0,1) attacks base
        let unitAt01 = unitAt(state, 0, 1);
        if (unitAt01 && unitAt01.owner_side === BOT_SIDE && canAttackBase(unitAt01, BOT_TARGET_SIDE)) {
            await botAttackBaseWithUnit(gameId, unitAt01.id);
            await sleep(120);
            state = await fetchGameStateRaw(gameId);
        }

        // 4) unit at (0,2) -> (0,1) -> attack base
        let unitAt02 = unitAt(state, 0, 2);
        if (unitAt02 && unitAt02.owner_side === BOT_SIDE) {
            if (cellEmpty(state, 0, 1) && canMoveByType(unitAt02, 0, 1)) {
                await botMoveUnit(gameId, unitAt02.id, 0, 1);
                await sleep(120);
                state = await fetchGameStateRaw(gameId);
            }

            unitAt01 = unitAt(state, 0, 1);
            if (unitAt01 && unitAt01.owner_side === BOT_SIDE && canAttackBase(unitAt01, BOT_TARGET_SIDE)) {
                await botAttackBaseWithUnit(gameId, unitAt01.id);
                await sleep(120);
                state = await fetchGameStateRaw(gameId);
            }
        }

        // 5) lines x=1..3:
        // сначала атака по линии перед собой (x-1, включая диагональ по правилам),
        // затем шаг вперёд, затем ещё одна попытка атаки (если атака не потрачена)
        for (const lineX of [1, 2, 3]) {
            state = await fetchGameStateRaw(gameId);
            const lineUnits = getOwnUnits(state, BOT_SIDE)
                .filter(u => u.position_x === lineX)
                .sort((a, b) => a.position_y - b.position_y);

            for (const myUnit of lineUnits) {
                state = await fetchGameStateRaw(gameId);
                const me = getOwnUnits(state, BOT_SIDE).find(u => u.id === myUnit.id);
                if (!me) continue;

                const frontX = lineX - 1;
                const targetOnFrontLine = findFrontLineTargetForUnit(state, me, frontX);

                if (targetOnFrontLine) {
                    await botAttackUnit(gameId, me.id, targetOnFrontLine.id);
                    await sleep(120);
                    state = await fetchGameStateRaw(gameId);
                }

                const meAfter = getOwnUnits(state, BOT_SIDE).find(u => u.id === myUnit.id);
                if (!meAfter) continue;

                const movedForward = cellEmpty(state, frontX, meAfter.position_y)
                    && canMoveByType(meAfter, frontX, meAfter.position_y);

                if (movedForward) {
                    await botMoveUnit(gameId, meAfter.id, frontX, meAfter.position_y);
                    await sleep(120);
                    state = await fetchGameStateRaw(gameId);

                    const movedUnit = getOwnUnits(state, BOT_SIDE).find(u => u.id === myUnit.id);
                    if (movedUnit && !movedUnit.has_attacked_this_turn) {
                        await botTryAttackBestForUnit(gameId, movedUnit.id);
                        await sleep(120);
                    }
                }
            }
        }

        // 6) deploy to line x=3 and try attack
        for (const deployCell of [{ x: 3, y: 1 }, { x: 3, y: 2 }]) {
            state = await fetchGameStateRaw(gameId);
            const botPlayer = getPlayerBySide(state, BOT_SIDE);
            if (!botPlayer) break;
            if (!cellEmpty(state, deployCell.x, deployCell.y)) continue;

            const hand = botPlayer.hand || [];
            const playable = hand.find(card => (botPlayer.supplies_current || 0) >= (CARD_COSTS[card.type] || 999));
            if (!playable) continue;

            const deployed = await botDeploy(gameId, playable.type, deployCell.x, deployCell.y);
            if (!deployed) continue;

            await sleep(120);
            state = await fetchGameStateRaw(gameId);

            const justDeployed = getOwnUnits(state, BOT_SIDE).find(
                u => u.position_x === deployCell.x && u.position_y === deployCell.y
            );

            if (justDeployed) {
                await botTryAttackBestForUnit(gameId, justDeployed.id);
                await sleep(120);
            }
        }

        // 7) archers attack lowest hp enemy
        state = await fetchGameStateRaw(gameId);
        const myArchers = getOwnUnits(state, BOT_SIDE)
            .filter(u => u.type === 'archer' && !u.has_attacked_this_turn);

        for (const archer of myArchers) {
            state = await fetchGameStateRaw(gameId);
            const freshArcher = getOwnUnits(state, BOT_SIDE).find(u => u.id === archer.id);
            if (!freshArcher || freshArcher.has_attacked_this_turn) continue;

            const target = findLowestHpEnemy(state, BOT_SIDE);
            if (!target) break;

            await botAttackUnit(gameId, freshArcher.id, target.id);
            await sleep(120);
        }

        // 8) base attacks lowest hp enemy
        state = await fetchGameStateRaw(gameId);
        const mePlayer = getPlayerBySide(state, BOT_SIDE);
        if (mePlayer && !mePlayer.base_has_attacked_this_turn) {
            const target = findLowestHpEnemy(state, BOT_SIDE);
            if (target) {
                await botAttackWithBaseToUnit(gameId, target.id);
                await sleep(120);
            }
        }

        // 9) end turn
        await botEndTurn(gameId);
    }

    async function maybeRunBotTurn(gameId) {
        if (!isEnabled(gameId)) return;
        if (botTurnInProgress) return;

        const state = await fetchGameStateRaw(gameId);
        if (!state || !state.success) return;
        if (!state.game || state.game.status !== 'active') return;
        if (state.current_player_side !== BOT_SIDE) return;

        botTurnInProgress = true;
        try {
            await runBotTurn(gameId);
        } catch (e) {
            console.error('Bot turn failed:', e);
        } finally {
            botTurnInProgress = false;
            window.location.reload();
        }
    }

    window.BotRunner = {
        maybeRunBotTurn,
        isEnabled,
        setEnabled
    };
})();
