(function () {
    const root = document.getElementById('replay-root');
    if (!root) return;

    const gameId = root.getAttribute('data-game-id');
    const p1Name = root.getAttribute('data-player-1-name') || 'Игрок 1';
    const p2Name = root.getAttribute('data-player-2-name') || 'Игрок 2';

    const boardEl = document.getElementById('replay-board');
    const eventsListEl = document.getElementById('events-list');

    const btnPlay = document.getElementById('btn-play');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const speedEl = document.getElementById('speed');

    const stepCurrentEl = document.getElementById('step-current');
    const stepTotalEl = document.getElementById('step-total');

    const metaTurnEl = document.getElementById('meta-turn');
    const metaRoundEl = document.getElementById('meta-round');
    const metaSideEl = document.getElementById('meta-side');

    const p1BaseHpEl = document.getElementById('p1-base-hp');
    const p2BaseHpEl = document.getElementById('p2-base-hp');

    const hand1El = document.getElementById('hand-1');
    const hand2El = document.getElementById('hand-2');
    const hand1CountEl = document.getElementById('hand-1-count');
    const hand2CountEl = document.getElementById('hand-2-count');

    let events = [];
    let index = 0;
    let timer = null;
    let isPlaying = false;
    let cardStats = {};

    const state = {
        turn: 1,
        round: 1,
        currentSide: 'player_1',
        p1BaseHp: 25,
        p2BaseHp: 25,
        p1BaseAttack: 1,
        p2BaseAttack: 1,
        p1Supplies: 0,
        p2Supplies: 0,
        units: new Map(),
        hand1: [],
        hand2: [],
    };

    function getCardStats(type) {
        const fallback = { max_hp: 0, hp: 0, attack_power: 0, movement_points: 0 };
        const byType = cardStats[String(type || '')];
        if (!byType || typeof byType !== 'object') {
            return fallback;
        }

        return {
            max_hp: Number(byType.max_hp || 0),
            hp: Number(byType.hp || 0),
            attack_power: Number(byType.attack_power || 0),
            movement_points: Number(byType.movement_points || 0),
        };
    }

    function replayCardCost(type) {
        const costs = { archer: 3, berserker: 4, infantry: 2, scout: 1 };
        return Number(costs[String(type || '')] || 0);
    }

    function sideLabel(side) {
        return side === 'player_1' ? p1Name : p2Name;
    }

    function baseCell(side) {
        return side === 'player_1' ? { x: 0, y: 0 } : { x: 4, y: 2 };
    }

    function createEmptyBoard() {
        boardEl.innerHTML = '';
        for (let y = 2; y >= 0; y--) {
            for (let x = 0; x < 5; x++) {
                const cell = document.createElement('div');
                cell.className = 'cell';
                cell.setAttribute('data-x', String(x));
                cell.setAttribute('data-y', String(y));
                cell.innerHTML = `<div class="cell-number">${x},${y}</div>`;
                boardEl.appendChild(cell);
            }
        }
    }

    function getCell(x, y) {
        return boardEl.querySelector(`.cell[data-x="${x}"][data-y="${y}"]`);
    }

    function getUnitCellById(unitId) {
        const unitEl = boardEl.querySelector(`.board-unit[data-unit-id="${unitId}"]`);
        return unitEl ? unitEl.closest('.cell') : null;
    }

    function hearts(current, max) {
        const safeMax = Math.max(0, Number(max || 0));
        const safeCurrent = Math.max(0, Number(current || 0));
        let html = '';
        for (let i = 1; i <= safeMax; i++) {
            html += `<span class="hp-heart ${i <= safeCurrent ? 'alive' : 'lost'}">❤️</span>`;
        }
        return html;
    }

    function renderHands() {
        hand1El.innerHTML = '';
        hand2El.innerHTML = '';

        state.hand1.forEach((card) => hand1El.appendChild(createCard(card)));
        state.hand2.forEach((card) => hand2El.appendChild(createCard(card)));

        hand1CountEl.textContent = String(state.hand1.length);
        hand2CountEl.textContent = String(state.hand2.length);
    }

    function createCard(card) {
        const type = typeof card === 'string' ? card : String(card?.type || 'unit');
        const st = getCardStats(type);
        const cardEl = document.createElement('div');
        cardEl.className = 'card';
        cardEl.innerHTML = `
            ${String(type || 'unit').charAt(0).toUpperCase()}${String(type || 'unit').slice(1)} (${replayCardCost(type)})
            <div class="card-stats">
                <div><div>HP</div><strong>${st.max_hp}</strong></div>
                <div><div>Атака</div><strong>${st.attack_power}</strong></div>
                <div><div>Движ.</div><strong>${st.movement_points}</strong></div>
            </div>
        `;
        return cardEl;
    }

    function renderBoard() {
        createEmptyBoard();

        const p1Base = baseCell('player_1');
        const p2Base = baseCell('player_2');

        const p1Cell = getCell(p1Base.x, p1Base.y);
        const p2Cell = getCell(p2Base.x, p2Base.y);

        if (p1Cell) {
            p1Cell.innerHTML += `
                <div class="player-base player-1-base" data-base-side="player_1">
                    <div class="base-content base-content--top">
                        <span>⚔️ <span class="attack-value">${state.p1BaseAttack}</span></span>
                        <span class="supplies-icons">${'🌾'.repeat(Math.max(0, state.p1Supplies))}</span>
                    </div>
                    <div class="base-content">
                        <div>❤️</div>
                        <div class="hp-value">${'❤️'.repeat(Math.max(0, state.p1BaseHp))}</div>
                    </div>
                </div>
            `;
        }

        if (p2Cell) {
            p2Cell.innerHTML += `
                <div class="player-base player-2-base" data-base-side="player_2">
                    <div class="base-content base-content--top">
                        <span>⚔️ <span class="attack-value">${state.p2BaseAttack}</span></span>
                        <span class="supplies-icons">${'🌾'.repeat(Math.max(0, state.p2Supplies))}</span>
                    </div>
                    <div class="base-content">
                        <div>❤️</div>
                        <div class="hp-value">${'❤️'.repeat(Math.max(0, state.p2BaseHp))}</div>
                    </div>
                </div>
            `;
        }

        state.units.forEach((u) => {
            if (u.state !== 'board') return;
            const cell = getCell(u.x, u.y);
            if (!cell) return;

            const sideClass = u.ownerSide === 'player_1' ? 'player-1' : 'player-2';
            cell.innerHTML += `
                <div class="board-unit ${sideClass}" data-unit-id="${u.id}">
                    <div class="board-unit-title">#${u.id} ${capitalize(u.type)}</div>
                    <div class="hp-hearts">${hearts(u.hp, u.maxHp)}</div>
                    <div>⚔️ ${u.attackPower} | 🐎 ${Math.max(0, Number(u.movementPoints || 0))}</div>
                </div>
            `;
        });
    }

    function capitalize(v) {
        const s = String(v || '');
        return s.length ? s.charAt(0).toUpperCase() + s.slice(1) : s;
    }

    function applyEvent(event) {
        const p = event.payload || {};
        state.turn = Number(event.turn_number || state.turn);
        state.round = Number(event.round_number || state.round);

        if (event.event_type === 'deploy_card') {
            const toX = Number(p?.to?.x);
            const toY = Number(p?.to?.y);
            const unitType = String(p.unit_type || 'infantry');
            const unitId = Number(p.unit_id || 0) || -Number(event.sequence || 0);
            const stats = getCardStats(unitType);

            if (Number.isFinite(toX) && Number.isFinite(toY)) {
                state.units.set(unitId, {
                    id: unitId,
                    type: unitType,
                    ownerSide: event.actor_side,
                    x: toX,
                    y: toY,
                    hp: Number(stats.hp || stats.max_hp || 0),
                    maxHp: Number(stats.max_hp || 0),
                    attackPower: Number(stats.attack_power || 0),
                    movementPoints: Number(stats.movement_points || 0),
                    state: 'board',
                });

                if (event.actor_side === 'player_1' && Array.isArray(p.hand_after)) {
                    state.hand1 = p.hand_after.slice();
                }
                if (event.actor_side === 'player_2' && Array.isArray(p.hand_after)) {
                    state.hand2 = p.hand_after.slice();
                }

                if (event.actor_side === 'player_1' && state.hand1.length === 0 && Array.isArray(p.hand_before)) {
                    state.hand1 = p.hand_before.slice();
                }
                if (event.actor_side === 'player_2' && state.hand2.length === 0 && Array.isArray(p.hand_before)) {
                    state.hand2 = p.hand_before.slice();
                }
            }
        }

        if (event.event_type === 'move_unit') {
            const unitId = Number(p.unit_id || 0);
            const toX = Number(p?.to?.x);
            const toY = Number(p?.to?.y);

            if (state.units.has(unitId) && Number.isFinite(toX) && Number.isFinite(toY)) {
                const unit = state.units.get(unitId);
                unit.x = toX;
                unit.y = toY;
            }
        }

        if (event.event_type === 'attack_unit') {
            const targetId = Number(p.target_unit_id || 0);
            const attackerId = Number(p.attacker_unit_id || 0);

            if (state.units.has(targetId)) {
                const targetUnit = state.units.get(targetId);
                const targetHpAfterFromEvent = Number(p.target_hp_after);

                if (Number.isFinite(targetHpAfterFromEvent)) {
                    targetUnit.hp = Math.max(0, targetHpAfterFromEvent);
                } else {
                    const dmg = Number(p.damage || 1);
                    targetUnit.hp = Math.max(0, Number(targetUnit.hp || 0) - Math.max(1, dmg));
                }

                const targetStateAfter = String(p?.target_after?.state || '');
                const targetDied = Boolean(p.target_died) || targetUnit.hp <= 0 || (targetStateAfter !== '' && targetStateAfter !== 'board');

                if (targetDied) {
                    targetUnit.state = 'graveyard';
                }
            }

            if (state.units.has(attackerId)) {
                const attackerUnit = state.units.get(attackerId);

                const attackerHpAfterFromEvent = Number(p?.attacker_after?.hp);
                if (Number.isFinite(attackerHpAfterFromEvent)) {
                    attackerUnit.hp = Math.max(0, attackerHpAfterFromEvent);
                }

                const attackerStateAfter = String(p?.attacker_after?.state || '');
                const attackerDied = attackerUnit.hp <= 0 || (attackerStateAfter !== '' && attackerStateAfter !== 'board');

                if (attackerDied) {
                    attackerUnit.state = 'graveyard';
                }
            }
        }

        if (event.event_type === 'attack_with_base') {
            const targetId = Number(p.target_unit_id || 0);
            if (state.units.has(targetId)) {
                const unit = state.units.get(targetId);
                const hpAfterFromEvent = Number(p.target_hp_after);

                if (Number.isFinite(hpAfterFromEvent)) {
                    unit.hp = Math.max(0, hpAfterFromEvent);
                } else {
                    const dmg = Number(p.damage || 1);
                    unit.hp = Math.max(0, Number(unit.hp || 0) - Math.max(1, dmg));
                }

                const dead = Boolean(p.target_died) || unit.hp <= 0;
                if (dead) {
                    unit.state = 'graveyard';
                }
            }
        }

        if (event.event_type === 'attack_base') {
            const targetSide = String(p.target_side || '');
            const hpAfter = Number(p.target_base_hp_after);

            if (targetSide === 'player_1') {
                state.p1BaseHp = Number.isFinite(hpAfter)
                    ? Math.max(0, hpAfter)
                    : Math.max(0, state.p1BaseHp - Math.max(1, Number(p.damage || 1)));
            }

            if (targetSide === 'player_2') {
                state.p2BaseHp = Number.isFinite(hpAfter)
                    ? Math.max(0, hpAfter)
                    : Math.max(0, state.p2BaseHp - Math.max(1, Number(p.damage || 1)));
            }
        }
        if (event.event_type === 'end_turn') {
            const hands = p.hands || {};

            if (Array.isArray(hands?.player_1?.after)) {
                state.hand1 = hands.player_1.after.slice();
            }
            if (Array.isArray(hands?.player_2?.after)) {
                state.hand2 = hands.player_2.after.slice();
            }

            if (state.hand1.length === 0 && Array.isArray(hands?.player_1?.before)) {
                state.hand1 = hands.player_1.before.slice();
            }
            if (state.hand2.length === 0 && Array.isArray(hands?.player_2?.before)) {
                state.hand2 = hands.player_2.before.slice();
            }

            state.currentSide = String(p.next_side || (event.actor_side === 'player_1' ? 'player_2' : 'player_1'));
        } else {
            state.currentSide = String(event.actor_side || state.currentSide);
        }
    }

    function resetState() {
        state.turn = 1;
        state.round = 1;
        state.currentSide = 'player_1';
        state.p1BaseHp = 25;
        state.p2BaseHp = 25;
        state.p1BaseAttack = 1;
        state.p2BaseAttack = 1;
        state.p1Supplies = 0;
        state.p2Supplies = 0;
        state.units.clear();
        state.hand1 = [];
        state.hand2 = [];
    }

    function renderMeta(stepIndex) {
        const active = events[stepIndex - 1] || null;
        metaTurnEl.textContent = String(active ? active.turn_number : 1);
        metaRoundEl.textContent = String(active ? active.round_number : 1);
        metaSideEl.textContent = sideLabel(state.currentSide);

        p1BaseHpEl.textContent = String(state.p1BaseHp);
        p2BaseHpEl.textContent = String(state.p2BaseHp);
        stepCurrentEl.textContent = String(stepIndex);
    }

    function formatCoords(point) {
        const x = Number(point?.x);
        const y = Number(point?.y);

        if (!Number.isFinite(x) || !Number.isFinite(y)) {
            return '(?, ?)';
        }

        return `(${x},${y})`;
    }

    function eventDescription(event) {
        const p = event.payload || {};
        const actor = sideLabel(event.actor_side);

        if (event.event_type === 'deploy_card') {
            const unitType = String(p.unit_type || 'unit');
            const to = formatCoords(p.to);
            const unitId = p.unit_id ? ` #${p.unit_id}` : '';
            return `${actor} выставил ${unitType}${unitId} на клетку ${to}`;
        }

        if (event.event_type === 'move_unit') {
            const unitId = p.unit_id ?? '?';
            const from = formatCoords(p.from);
            const to = formatCoords(p.to);
            return `${actor} переместил юнита #${unitId} из ${from} в ${to}`;
        }

        if (event.event_type === 'attack_unit') {
            const attackerId = p.attacker_unit_id ?? '?';
            const targetId = p.target_unit_id ?? '?';
            const damage = Number(p.damage || 0);
            const hpBefore = p.target_hp_before ?? '?';
            const hpAfter = p.target_hp_after ?? '?';
            const died = Boolean(p.target_died);

            return `${actor} атаковал юнитом #${attackerId} юнита #${targetId}: урон ${damage}, HP цели ${hpBefore} → ${hpAfter}${died ? ' (цель уничтожена)' : ''}`;
        }

        if (event.event_type === 'attack_with_base') {
            const targetId = p.target_unit_id ?? '?';
            const damage = Number(p.damage || 0);
            const hpBefore = p.target_hp_before ?? '?';
            const hpAfter = p.target_hp_after ?? '?';
            const died = Boolean(p.target_died);

            return `${actor} атаковал штабом юнита #${targetId}: урон ${damage}, HP цели ${hpBefore} → ${hpAfter}${died ? ' (цель уничтожена)' : ''}`;
        }

        if (event.event_type === 'attack_base') {
            const targetSide = String(p.target_side || '');
            const targetName = targetSide === 'player_1' ? p1Name : (targetSide === 'player_2' ? p2Name : 'противника');
            const sourceType = String(p.source_type || 'unit');
            const damage = Number(p.damage || 0);
            const hpBefore = p.target_base_hp_before ?? '?';
            const hpAfter = p.target_base_hp_after ?? '?';

            if (sourceType === 'base') {
                return `${actor} атаковал вражеский штаб (${targetName}): урон ${damage}, HP базы ${hpBefore} → ${hpAfter}`;
            }

            const attackerId = p.attacker_unit_id ?? '?';
            return `${actor} атаковал штаб ${targetName} юнитом #${attackerId}: урон ${damage}, HP базы ${hpBefore} → ${hpAfter}`;
        }

        if (event.event_type === 'end_turn') {
            const nextSide = String(p.next_side || '');
            return `${actor} завершил ход. Следующий ход: ${sideLabel(nextSide)}`;
        }

        if (event.event_type === 'game_finished') {
            const winnerSide = String(p.winner_side || '');
            const winnerName = winnerSide === 'player_1' ? p1Name : (winnerSide === 'player_2' ? p2Name : 'неизвестно');
            const reason = p.reason ? ` Причина: ${p.reason}.` : '';
            return `Матч завершён. Победитель: ${winnerName}.${reason}`;
        }

        return `${actor} выполнил действие: ${event.event_type}`;
    }

    function renderEventsList(activeIndex) {
        eventsListEl.innerHTML = '';

        events.forEach((event, i) => {
            const item = document.createElement('div');
            item.className = `event-item${i === activeIndex ? ' active' : ''}`;
            item.innerHTML = `
                <div class="event-meta">#${event.sequence} · Ход ${event.turn_number} · Раунд ${event.round_number}</div>
                <div>${eventDescription(event)}</div>
            `;
            item.addEventListener('click', () => {
                stop();
                index = i + 1;
                rebuildTo(index);
            });
            eventsListEl.appendChild(item);
        });

        scrollLogToActiveEvent(activeIndex);
    }

    function scrollLogToActiveEvent(activeIndex) {
        if (activeIndex < 0) {
            eventsListEl.scrollTo({ top: 0, behavior: 'auto' });
            return;
        }

        const activeEl = eventsListEl.querySelector('.event-item.active');
        if (!activeEl) return;

        const containerTop = eventsListEl.scrollTop;
        const containerHeight = eventsListEl.clientHeight;
        const itemTop = activeEl.offsetTop;
        const itemHeight = activeEl.offsetHeight;

        const targetScrollTop = itemTop - (containerHeight / 2) + (itemHeight / 2);
        const maxScrollTop = Math.max(0, eventsListEl.scrollHeight - containerHeight);
        const nextScrollTop = Math.min(maxScrollTop, Math.max(0, targetScrollTop));

        eventsListEl.scrollTo({
            top: nextScrollTop,
            behavior: isPlaying ? 'smooth' : 'auto',
        });
    }

    function showDamageAtCell(cell, damage) {
        if (!cell || !Number.isFinite(damage) || damage <= 0) return;
        const bubble = document.createElement('div');
        bubble.className = 'replay-damage-pop';
        bubble.textContent = `-${damage}`;
        cell.appendChild(bubble);
        setTimeout(() => bubble.remove(), 620);
    }

    function getElementCenter(el) {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return {
            x: r.left + r.width / 2,
            y: r.top + r.height / 2,
        };
    }

    function animateArrowFlight(fromEl, toEl) {
        return new Promise((resolve) => {
            const from = getElementCenter(fromEl);
            const to = getElementCenter(toEl);

            if (!from || !to) {
                resolve();
                return;
            }

            const arrow = document.createElement('div');
            arrow.className = 'replay-arrow';
            arrow.style.left = `${from.x - 5}px`;
            arrow.style.top = `${from.y - 5}px`;
            document.body.appendChild(arrow);

            const startedAt = performance.now();
            const duration = 260;

            const step = (now) => {
                const t = Math.min(1, (now - startedAt) / duration);
                const x = from.x + (to.x - from.x) * t;
                const y = from.y + (to.y - from.y) * t;

                arrow.style.left = `${x - 5}px`;
                arrow.style.top = `${y - 5}px`;

                if (t < 1) {
                    requestAnimationFrame(step);
                    return;
                }

                arrow.remove();
                resolve();
            };

            requestAnimationFrame(step);
        });
    }

    function animateMeleeStrike(attackerEl, targetEl) {
        return new Promise((resolve) => {
            if (!attackerEl || !targetEl) {
                resolve();
                return;
            }

            attackerEl.classList.add('replay-melee-dash');
            setTimeout(() => {
                attackerEl.classList.remove('replay-melee-dash');
                resolve();
            }, 190);
        });
    }

    async function animateEvent(event) {
        const p = event.payload || {};

        if (event.event_type === 'move_unit') {
            const fromX = Number(p?.from?.x);
            const fromY = Number(p?.from?.y);
            const toX = Number(p?.to?.x);
            const toY = Number(p?.to?.y);

            const fromCell = Number.isFinite(fromX) && Number.isFinite(fromY) ? getCell(fromX, fromY) : null;
            const toCell = Number.isFinite(toX) && Number.isFinite(toY) ? getCell(toX, toY) : null;

            if (fromCell) fromCell.classList.add('replay-anim-move-from');
            if (toCell) toCell.classList.add('replay-anim-move-to');

            await new Promise((r) => setTimeout(r, 260));

            if (fromCell) fromCell.classList.remove('replay-anim-move-from');
            if (toCell) toCell.classList.remove('replay-anim-move-to');
            return;
        }

        if (event.event_type === 'attack_unit' || event.event_type === 'attack_with_base') {
            const attackerId = Number(p.attacker_unit_id || 0);
            const targetId = Number(p.target_unit_id || 0);

            const attackerCell = attackerId > 0 ? getUnitCellById(attackerId) : null;
            const targetCell = targetId > 0 ? getUnitCellById(targetId) : null;

            const attackerEl = attackerCell ? attackerCell.querySelector('.board-unit, .player-base') : null;
            const targetEl = targetCell ? targetCell.querySelector('.board-unit, .player-base') : null;

            const attackerType = String(p?.attacker_before?.type || '').toLowerCase();
            const isArcherShot = attackerType === 'archer';

            if (attackerCell) attackerCell.classList.add('replay-anim-hit-attacker');
            if (targetCell) targetCell.classList.add('replay-anim-hit-target');

            if (isArcherShot) {
                await animateArrowFlight(attackerEl, targetEl);
            } else {
                await animateMeleeStrike(attackerEl, targetEl);
            }

            const dmg = Number(p.damage || 0);
            showDamageAtCell(targetCell, dmg);

            await new Promise((r) => setTimeout(r, 220));

            if (attackerCell) attackerCell.classList.remove('replay-anim-hit-attacker');
            if (targetCell) targetCell.classList.remove('replay-anim-hit-target');
            return;
        }

        if (event.event_type === 'attack_base') {
            const attackerId = Number(p.attacker_unit_id || 0);
            const targetSide = String(p.target_side || '');

            const attackerCell = attackerId > 0 ? getUnitCellById(attackerId) : null;
            const attackerEl = attackerCell ? attackerCell.querySelector('.board-unit, .player-base') : null;

            const targetCell = targetSide === 'player_1'
                ? getCell(0, 0)
                : (targetSide === 'player_2' ? getCell(4, 2) : null);

            const targetEl = targetCell ? targetCell.querySelector('.board-unit, .player-base') : null;

            if (attackerCell) attackerCell.classList.add('replay-anim-hit-attacker');
            if (targetCell) targetCell.classList.add('replay-anim-hit-target');

            const sourceType = String(p.source_type || '');
            if (sourceType === 'unit') {
                const attackerType = String(p?.attacker_before?.type || '').toLowerCase();
                if (attackerType === 'archer') {
                    await animateArrowFlight(attackerEl, targetEl);
                } else {
                    await animateMeleeStrike(attackerEl, targetEl);
                }
            }

            showDamageAtCell(targetCell, Number(p.damage || 0));

            await new Promise((r) => setTimeout(r, 220));

            if (attackerCell) attackerCell.classList.remove('replay-anim-hit-attacker');
            if (targetCell) targetCell.classList.remove('replay-anim-hit-target');
            return;
        }
    }

    function rebuildTo(stepIndex) {
        resetState();
        for (let i = 0; i < stepIndex; i++) {
            applyEvent(events[i]);
        }

        renderBoard();
        renderHands();
        renderMeta(stepIndex);
        renderEventsList(stepIndex - 1);
    }

    async function stepForwardAnimated() {
        if (index >= events.length) return false;

        index += 1;
        rebuildTo(index);

        const active = events[index - 1];
        await animateEvent(active);

        return true;
    }

    function play() {
        if (isPlaying) return;
        isPlaying = true;
        btnPlay.textContent = '⏸ Pause';

        const tick = async () => {
            if (!isPlaying) return;
            const advanced = await stepForwardAnimated();
            if (!advanced) {
                stop();
                return;
            }
            timer = window.setTimeout(tick, Number(speedEl.value || 900));
        };

        timer = window.setTimeout(tick, Number(speedEl.value || 900));
    }

    function stop() {
        isPlaying = false;
        btnPlay.textContent = '▶ Play';
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    async function init() {
        createEmptyBoard();

        const response = await fetch(`/api/games/${gameId}/replay-events`, {
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();

        if (!payload.success) {
            eventsListEl.innerHTML = '<div class="event-item">Не удалось загрузить replay</div>';
            return;
        }

        events = Array.isArray(payload.events) ? payload.events : [];
        cardStats = (payload.card_stats && typeof payload.card_stats === 'object') ? payload.card_stats : {};
        stepTotalEl.textContent = String(events.length);

        rebuildTo(0);

        btnPlay.addEventListener('click', () => (isPlaying ? stop() : play()));

        btnPrev.addEventListener('click', () => {
            stop();
            index = Math.max(0, index - 1);
            rebuildTo(index);
        });

        btnNext.addEventListener('click', async () => {
            stop();
            await stepForwardAnimated();
        });
    }


    init().catch((e) => {
        eventsListEl.innerHTML = `<div class="event-item">Ошибка: ${String(e.message || e)}</div>`;
    });
})();
