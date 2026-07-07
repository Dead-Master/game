// JavaScript для игры

let currentPlayerSide = 'player_1';
let selectedUnit = null;
let selectedBaseSide = null;
let moveTargets = new Map();
let previewedUnitElements = new Set();
let previewedBaseElements = new Set();
let previewedBlockedAttackElements = new Set();
let previewedAllowedAttackElements = new Set();

document.addEventListener('DOMContentLoaded', function() {
    const gameId = getGameIdFromUrl();

    if (!gameId) {
        console.error('Game ID not found');
        return;
    }

    const gameElement = document.querySelector('#game-container');
    if (gameElement && gameElement.getAttribute('data-current-player-side')) {
        currentPlayerSide = gameElement.getAttribute('data-current-player-side');
    }

    initializeGameField(gameId);
    setupEventListeners(gameId);
    bindAttackPreviewHandlers();
});

function getGameIdFromUrl() {
    const pathParts = window.location.pathname.split('/');
    const gameIdIndex = pathParts.indexOf('game') + 1;

    if (gameIdIndex > 0 && pathParts[gameIdIndex]) {
        return pathParts[gameIdIndex];
    }

    const gameElement = document.querySelector('[data-game-id]');
    if (gameElement) {
        return gameElement.getAttribute('data-game-id');
    }

    return null;
}

function initializeGameField(gameId) {
    console.log(`Initializing game field for game ID: ${gameId}`);
}

function setupEventListeners(gameId) {
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('click', function() {
            clearSelectedBase();
            clearSelectedUnit();

            cards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
        });
    });

    const baseElements = document.querySelectorAll('.player-base');
    baseElements.forEach(baseEl => {
        baseEl.addEventListener('click', function(e) {
            e.stopPropagation();

            const ownerSide = this.getAttribute('data-owner-side');
            if (!ownerSide) return;

            if (ownerSide === currentPlayerSide) {
                clearSelectedUnit();
                document.querySelectorAll('.card').forEach(c => c.classList.remove('selected'));

                if (selectedBaseSide === ownerSide) {
                    clearSelectedBase();
                    return;
                }

                selectedBaseSide = ownerSide;
                this.classList.add('selected-unit');
                return;
            }

            if (selectedUnit) {
                attackBase(getGameIdFromUrl(), ownerSide, selectedUnit.unitId);
                clearSelectedUnit();
                clearSelectedBase();
                return;
            }

            if (selectedBaseSide === currentPlayerSide) {
                attackBase(getGameIdFromUrl(), ownerSide, null);
                clearSelectedBase();
            }
        });
    });

    const unitElements = document.querySelectorAll('.board-unit');
    unitElements.forEach(unitEl => {
        unitEl.addEventListener('click', function(e) {
            e.stopPropagation();

            const ownerSide = this.getAttribute('data-owner-side');
            const unitId = parseInt(this.getAttribute('data-unit-id'), 10);

            if (ownerSide !== currentPlayerSide) {
                if (selectedUnit) {
                    attackUnit(getGameIdFromUrl(), selectedUnit.unitId, unitId);
                    clearSelectedUnit();
                    clearSelectedBase();
                    return;
                }

                if (selectedBaseSide === currentPlayerSide) {
                    attackWithBase(getGameIdFromUrl(), unitId);
                    clearSelectedBase();
                }

                return;
            }

            clearSelectedBase();
            document.querySelectorAll('.card').forEach(c => c.classList.remove('selected'));

            if (selectedUnit && selectedUnit.unitId === unitId) {
                clearSelectedUnit();
                return;
            }

            selectUnit(this);
        });
    });

    const cells = document.querySelectorAll('.cell');
    cells.forEach(cell => {
        cell.addEventListener('click', function() {
            const selectedCard = document.querySelector('.card.selected');
            if (selectedCard) {
                const cardType = selectedCard.getAttribute('data-card-type');
                const cellX = parseInt(this.getAttribute('data-x'), 10);
                const cellY = parseInt(this.getAttribute('data-y'), 10);

                const baseBySide = {
                    player_1: { x: 0, y: 0 },
                    player_2: { x: 4, y: 2 }
                };

                const base = baseBySide[currentPlayerSide] || baseBySide.player_1;
                const adjacentCells = getAdjacentCells(base.x, base.y);

                let isValid = false;
                for (let i = 0; i < adjacentCells.length; i++) {
                    if (adjacentCells[i].x === cellX && adjacentCells[i].y === cellY) {
                        isValid = true;
                        break;
                    }
                }

                if (isValid) {
                    deployCard(gameId, cardType, cellX, cellY);
                    selectedCard.classList.remove('selected');
                } else {
                    alert('Вы можете размещать карты только на соседних клетках со своим штабом!');
                }

                return;
            }

            if (selectedUnit) {
                const x = parseInt(this.getAttribute('data-x'), 10);
                const y = parseInt(this.getAttribute('data-y'), 10);
                const key = `${x}:${y}`;

                if (!moveTargets.has(key)) {
                    clearSelectedUnit();
                    return;
                }

                const target = moveTargets.get(key);
                if (target && target.type === 'move') {
                    moveUnit(gameId, selectedUnit.unitId, x, y);
                }

                clearSelectedUnit();
            }
        });
    });
}

function clearSelectedBase() {
    selectedBaseSide = null;
    document.querySelectorAll('.player-base.selected-unit').forEach(el => {
        el.classList.remove('selected-unit');
    });
}

function selectUnit(unitEl) {
    clearSelectedUnit();

    const unitId = parseInt(unitEl.getAttribute('data-unit-id'), 10);
    const movementPoints = parseInt(unitEl.getAttribute('data-movement-points') || '0', 10);
    const type = (unitEl.getAttribute('data-unit-type') || '').trim().toLowerCase();
    const x = parseInt(unitEl.getAttribute('data-x'), 10);
    const y = parseInt(unitEl.getAttribute('data-y'), 10);
    const attackPower = parseInt(unitEl.getAttribute('data-attack-power') || '0', 10);
    const hp = parseInt(unitEl.getAttribute('data-current-hp') || '0', 10);
    const maxHp = parseInt(unitEl.getAttribute('data-max-hp') || '0', 10);
    const hasAttacked = unitEl.getAttribute('data-has-attacked') === '1';
    const hasCounterAttacked = unitEl.getAttribute('data-has-counter-attacked') === '1';

    selectedUnit = {
        unitId,
        movementPoints,
        x,
        y,
        type,
        attackPower,
        hp,
        maxHp,
        hasAttacked,
        hasCounterAttacked,
        element: unitEl
    };

    unitEl.classList.add('selected-unit');
    highlightMoveTargets();
}

function clearSelectedUnit() {
    selectedUnit = null;
    moveTargets.clear();
    clearAttackPreview();

    document.querySelectorAll('.board-unit.selected-unit').forEach(el => {
        el.classList.remove('selected-unit');
    });

    document.querySelectorAll('.cell.move-allowed, .cell.move-allowed-empty, .cell.move-too-far-empty, .cell.attack-allowed-enemy, .cell.attack-too-far-enemy, .cell.attack-allowed-ranged-enemy, .cell.attack-blocked-ranged-enemy').forEach(cell => {
        cell.classList.remove('move-allowed', 'move-allowed-empty', 'move-too-far-empty', 'attack-allowed-enemy', 'attack-too-far-enemy', 'attack-allowed-ranged-enemy', 'attack-blocked-ranged-enemy');
    });
}

function highlightMoveTargets() {
    if (!selectedUnit) return;

    const occupancy = buildOccupancyMap();
    const cells = document.querySelectorAll('.cell');

    cells.forEach(cell => {
        const tx = parseInt(cell.getAttribute('data-x'), 10);
        const ty = parseInt(cell.getAttribute('data-y'), 10);

        const dx = Math.abs(selectedUnit.x - tx);
        const dy = Math.abs(selectedUnit.y - ty);

        const key = `${tx}:${ty}`;
        const occupant = occupancy.get(key);
        const isEmpty = !occupant;

        if (!isMoveAllowedByType(selectedUnit.type, selectedUnit.movementPoints, dx, dy)) {
            if (isEmpty) {
                cell.classList.add('move-too-far-empty');
            } else if (occupant.ownerSide !== currentPlayerSide) {
                if (selectedUnit.type === 'archer') {
                    if (selectedUnit.hasAttacked) {
                        cell.classList.add('attack-blocked-ranged-enemy');
                    } else {
                        cell.classList.add('attack-allowed-ranged-enemy');
                    }
                } else {
                    if (!selectedUnit.hasAttacked && isAttackAllowedByType(selectedUnit.type, dx, dy)) {
                        cell.classList.add('attack-allowed-enemy');
                    } else {
                        cell.classList.add('attack-too-far-enemy');
                    }
                }
            }
            return;
        }

        if (isEmpty) {
            cell.classList.add('move-allowed', 'move-allowed-empty');
            moveTargets.set(key, { x: tx, y: ty, type: 'move' });
            return;
        }

        if (occupant.ownerSide !== currentPlayerSide) {
            if (selectedUnit.type === 'archer') {
                if (selectedUnit.hasAttacked) {
                    cell.classList.add('attack-blocked-ranged-enemy');
                } else {
                    cell.classList.add('attack-allowed-ranged-enemy');
                }
            } else {
                if (!selectedUnit.hasAttacked && isAttackAllowedByType(selectedUnit.type, dx, dy)) {
                    cell.classList.add('attack-allowed-enemy');
                } else {
                    cell.classList.add('attack-too-far-enemy');
                }
            }
        }
    });
}

function isMoveAllowedByType(type, movementPoints, dx, dy) {
    if (dx === 0 && dy === 0) return false;

    if (type === 'infantry') {
        return (dx + dy === 1) || (dx === 1 && dy === 1);
    }

    if (type === 'archer') {
        return Math.max(dx, dy) <= movementPoints;
    }

    if (type === 'berserker') {
        return (dx + dy) === 1;
    }

    if (type === 'scout') {
        return (dx === 0 || dy === 0) && ((dx + dy) <= movementPoints);
    }

    return false;
}

function isAttackAllowedByType(type, dx, dy) {
    if (dx === 0 && dy === 0) return false;
    if (type === 'archer') return true;
    return Math.max(dx, dy) === 1;
}

function canCounterAttackByData(defender) {
    if (defender.hasCounterAttacked) return false;

    if (defender.type === 'berserker') {
        return !defender.hasAttacked;
    }

    return defender.type === 'infantry' || defender.type === 'scout';
}

function renderUnitHearts(unitEl, predictedDamage) {
    const heartsEl = unitEl.querySelector('.hp-hearts');
    if (!heartsEl) return;

    const currentHp = parseInt(heartsEl.getAttribute('data-current-hp') || '0', 10);
    const maxHp = parseInt(heartsEl.getAttribute('data-max-hp') || '0', 10);

    const predictedLost = Math.min(currentHp, Math.max(0, predictedDamage));
    const aliveAfter = Math.max(0, currentHp - predictedLost);
    const totalLostAfter = Math.max(0, maxHp - aliveAfter);

    let html = '';
    for (let i = 1; i <= maxHp; i++) {
        html += `<span class="hp-heart ${i <= (maxHp - totalLostAfter) ? 'alive' : 'lost'}">❤️</span>`;
    }

    heartsEl.innerHTML = html;
}

function resetUnitHearts(unitEl) {
    const heartsEl = unitEl.querySelector('.hp-hearts');
    if (!heartsEl) return;

    const currentHp = parseInt(heartsEl.getAttribute('data-current-hp') || '0', 10);
    const maxHp = parseInt(heartsEl.getAttribute('data-max-hp') || '0', 10);

    let html = '';
    for (let i = 1; i <= maxHp; i++) {
        html += `<span class="hp-heart ${i <= currentHp ? 'alive' : 'lost'}">❤️</span>`;
    }

    heartsEl.innerHTML = html;
}

function parseBaseHeartsCount(baseEl) {
    const hpValueEl = baseEl ? baseEl.querySelector('.hp-value') : null;
    if (!hpValueEl) return 0;

    const heartsMatches = hpValueEl.textContent.match(/❤️/g);
    return heartsMatches ? heartsMatches.length : 0;
}

function renderBaseHearts(baseEl, predictedDamage) {
    const hpValueEl = baseEl ? baseEl.querySelector('.hp-value') : null;
    if (!hpValueEl) return;

    if (!hpValueEl.dataset.originalHearts) {
        hpValueEl.dataset.originalHearts = hpValueEl.textContent;
    }

    const currentHp = parseBaseHeartsCount(baseEl);
    const predictedLost = Math.min(currentHp, Math.max(0, predictedDamage));
    const hpAfter = Math.max(0, currentHp - predictedLost);

    hpValueEl.textContent = '❤️'.repeat(hpAfter);
}

function resetBaseHearts(baseEl) {
    const hpValueEl = baseEl ? baseEl.querySelector('.hp-value') : null;
    if (!hpValueEl) return;

    if (typeof hpValueEl.dataset.originalHearts !== 'undefined') {
        hpValueEl.textContent = hpValueEl.dataset.originalHearts;
        delete hpValueEl.dataset.originalHearts;
    }
}

function clearAttackPreview() {
    previewedUnitElements.forEach((el) => resetUnitHearts(el));
    previewedUnitElements.clear();

    previewedBaseElements.forEach((el) => resetBaseHearts(el));
    previewedBaseElements.clear();

    previewedBlockedAttackElements.forEach((el) => resetBlockedAttackCursor(el));
    previewedBlockedAttackElements.clear();

    previewedAllowedAttackElements.forEach((el) => resetAllowedAttackCursor(el));
    previewedAllowedAttackElements.clear();
}

function getSelectedBaseElement() {
    if (!selectedBaseSide) return null;
    return document.querySelector(`.player-base[data-owner-side="${selectedBaseSide}"]`);
}

function getSelectedBaseAttackPower() {
    const baseEl = getSelectedBaseElement();
    if (!baseEl) return 0;
    return parseInt(baseEl.getAttribute('data-base-attack-power') || '0', 10);
}

function selectedBaseCanAttack() {
    const baseEl = getSelectedBaseElement();
    if (!baseEl) return false;
    return baseEl.getAttribute('data-base-has-attacked') !== '1';
}

function getUnitElementById(unitId) {
    return document.querySelector(`.board-unit[data-unit-id="${unitId}"]`);
}

function getElementCenter(el) {
    const rect = el.getBoundingClientRect();
    return {
        x: rect.left + rect.width / 2,
        y: rect.top + rect.height / 2
    };
}

function animateArrowFlight(fromEl, toEl) {
    return new Promise((resolve) => {
        if (!fromEl || !toEl) {
            resolve();
            return;
        }

        const from = getElementCenter(fromEl);
        const to = getElementCenter(toEl);
        const angle = Math.atan2(to.y - from.y, to.x - from.x) * 180 / Math.PI;
        const durationMs = 650;

        const arrow = document.createElement('div');
        arrow.textContent = '➤';
        arrow.style.position = 'fixed';
        arrow.style.left = `${from.x}px`;
        arrow.style.top = `${from.y}px`;
        arrow.style.fontSize = '28px';
        arrow.style.lineHeight = '1';
        arrow.style.pointerEvents = 'none';
        arrow.style.zIndex = '9999';
        arrow.style.transform = `translate(-50%, -50%) rotate(${angle}deg)`;
        arrow.style.transition = `left ${durationMs}ms linear, top ${durationMs}ms linear, opacity ${durationMs}ms linear`;
        arrow.style.opacity = '1';
        arrow.style.filter = 'drop-shadow(0 0 2px rgba(0,0,0,0.45))';

        document.body.appendChild(arrow);

        requestAnimationFrame(() => {
            arrow.style.left = `${to.x}px`;
            arrow.style.top = `${to.y}px`;
            arrow.style.opacity = '0.35';
        });

        setTimeout(() => {
            arrow.remove();
            resolve();
        }, durationMs + 20);
    });
}

function animateMeleeStrike(fromEl, toEl) {
    return new Promise((resolve) => {
        if (!fromEl || !toEl) {
            resolve();
            return;
        }

        const from = getElementCenter(fromEl);
        const to = getElementCenter(toEl);

        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const distance = Math.hypot(dx, dy);

        if (distance < 1) {
            resolve();
            return;
        }

        const maxLungePx = 40;
        const lunge = Math.min(maxLungePx, Math.max(18, distance * 0.28));
        const nx = dx / distance;
        const ny = dy / distance;

        const shiftX = nx * lunge;
        const shiftY = ny * lunge;

        const originalTransform = fromEl.style.transform || '';
        const originalTransition = fromEl.style.transition || '';

        fromEl.style.transition = 'transform 120ms ease-out';
        fromEl.style.transform = `${originalTransform} translate(${shiftX}px, ${shiftY}px)`;

        setTimeout(() => {
            fromEl.style.transition = 'transform 140ms ease-in';
            fromEl.style.transform = originalTransform;

            setTimeout(() => {
                fromEl.style.transition = originalTransition;
                resolve();
            }, 150);
        }, 125);
    });
}

function bindAttackPreviewHandlers() {
    const enemyUnits = document.querySelectorAll('.board-unit');
    const bases = document.querySelectorAll('.player-base');

    enemyUnits.forEach(unitEl => {
        unitEl.addEventListener('mouseenter', function () {
            const ownerSide = this.getAttribute('data-owner-side');
            if (ownerSide === currentPlayerSide) {
                clearAttackPreview();
                return;
            }

            const defender = {
                type: (this.getAttribute('data-unit-type') || '').trim().toLowerCase(),
                attackPower: parseInt(this.getAttribute('data-attack-power') || '0', 10),
                hp: parseInt(this.getAttribute('data-current-hp') || '0', 10),
                hasAttacked: this.getAttribute('data-has-attacked') === '1',
                hasCounterAttacked: this.getAttribute('data-has-counter-attacked') === '1',
                x: parseInt(this.getAttribute('data-x') || '0', 10),
                y: parseInt(this.getAttribute('data-y') || '0', 10),
                element: this
            };

            clearAttackPreview();

            if (selectedBaseSide === currentPlayerSide) {
                if (!selectedBaseCanAttack()) {
                    applyBlockedRangedAttackCursor(defender.element);
                    previewedBlockedAttackElements.add(defender.element);
                    return;
                }

                applyAllowedRangedAttackCursor(defender.element);
                previewedAllowedAttackElements.add(defender.element);

                const baseAttack = getSelectedBaseAttackPower();
                if (baseAttack > 0) {
                    renderUnitHearts(defender.element, baseAttack);
                    previewedUnitElements.add(defender.element);
                }
                return;
            }

            if (!selectedUnit) return;

            if (selectedUnit.hasAttacked) {
                if (selectedUnit.type === 'archer') {
                    applyBlockedRangedAttackCursor(defender.element);
                } else {
                    applyBlockedAttackCursor(defender.element);
                }
                previewedBlockedAttackElements.add(defender.element);
                return;
            }

            const dx = Math.abs(selectedUnit.x - defender.x);
            const dy = Math.abs(selectedUnit.y - defender.y);

            if (!isAttackAllowedByType(selectedUnit.type, dx, dy)) {
                return;
            }

            if (selectedUnit.type === 'archer') {
                applyAllowedRangedAttackCursor(defender.element);
            } else {
                applyAllowedAttackCursor(defender.element);
            }
            previewedAllowedAttackElements.add(defender.element);

            const defenderWillTake = selectedUnit.attackPower;
            renderUnitHearts(defender.element, defenderWillTake);
            previewedUnitElements.add(defender.element);

            const defenderHpAfter = defender.hp - defenderWillTake;
            const defenderCanCounter = defenderHpAfter > 0
                && selectedUnit.type !== 'archer'
                && canCounterAttackByData(defender);

            if (defenderCanCounter && selectedUnit.element) {
                renderUnitHearts(selectedUnit.element, defender.attackPower);
                previewedUnitElements.add(selectedUnit.element);
            }
        });

        unitEl.addEventListener('mouseleave', function () {
            clearAttackPreview();
        });
    });

    bases.forEach(baseEl => {
        baseEl.addEventListener('mouseenter', function () {
            const ownerSide = this.getAttribute('data-owner-side');
            if (!ownerSide || ownerSide === currentPlayerSide) {
                clearAttackPreview();
                return;
            }

            clearAttackPreview();

            if (selectedBaseSide === currentPlayerSide) {
                if (!selectedBaseCanAttack()) {
                    applyBlockedRangedAttackCursor(this);
                    previewedBlockedAttackElements.add(this);
                    return;
                }

                applyAllowedRangedAttackCursor(this);
                previewedAllowedAttackElements.add(this);

                const baseAttack = getSelectedBaseAttackPower();
                if (baseAttack > 0) {
                    renderBaseHearts(this, baseAttack);
                    previewedBaseElements.add(this);
                }
                return;
            }

            if (!selectedUnit) return;

            if (selectedUnit.hasAttacked) {
                if (selectedUnit.type === 'archer') {
                    applyBlockedRangedAttackCursor(this);
                } else {
                    applyBlockedAttackCursor(this);
                }
                previewedBlockedAttackElements.add(this);
                return;
            }

            const baseX = parseInt(this.getAttribute('data-cell-x') || '0', 10);
            const baseY = parseInt(this.getAttribute('data-cell-y') || '0', 10);
            const dx = Math.abs(selectedUnit.x - baseX);
            const dy = Math.abs(selectedUnit.y - baseY);

            if (!isAttackAllowedByType(selectedUnit.type, dx, dy)) {
                return;
            }

            if (selectedUnit.type === 'archer') {
                applyAllowedRangedAttackCursor(this);
            } else {
                applyAllowedAttackCursor(this);
            }
            previewedAllowedAttackElements.add(this);

            if (selectedUnit.attackPower > 0) {
                renderBaseHearts(this, selectedUnit.attackPower);
                previewedBaseElements.add(this);
            }
        });

        baseEl.addEventListener('mouseleave', function () {
            clearAttackPreview();
        });
    });
}

function buildOccupancyMap() {
    const map = new Map();

    document.querySelectorAll('.board-unit').forEach(unitEl => {
        const ux = parseInt(unitEl.getAttribute('data-x'), 10);
        const uy = parseInt(unitEl.getAttribute('data-y'), 10);
        map.set(`${ux}:${uy}`, {
            ownerSide: unitEl.getAttribute('data-owner-side'),
            type: (unitEl.getAttribute('data-unit-type') || '').trim().toLowerCase(),
            attackPower: parseInt(unitEl.getAttribute('data-attack-power') || '0', 10),
            hp: parseInt(unitEl.getAttribute('data-current-hp') || '0', 10),
            maxHp: parseInt(unitEl.getAttribute('data-max-hp') || '0', 10),
            hasAttacked: unitEl.getAttribute('data-has-attacked') === '1',
            hasCounterAttacked: unitEl.getAttribute('data-has-counter-attacked') === '1',
            element: unitEl
        });
    });

    return map;
}

function getAdjacentCells(x, y) {
    const adjacent = [];

    for (let dx = -1; dx <= 1; dx++) {
        for (let dy = -1; dy <= 1; dy++) {
            if (dx === 0 && dy === 0) continue;

            const nx = x + dx;
            const ny = y + dy;

            if (nx >= 0 && nx < 5 && ny >= 0 && ny < 3) {
                adjacent.push({ x: nx, y: ny });
            }
        }
    }

    return adjacent;
}

function deployCard(gameId, type, x, y) {
    fetch(`/api/games/${gameId}/deploy-card`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            side: currentPlayerSide,
            type: type,
            cell_x: x,
            cell_y: y
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось разместить карту: ' + (data.error || 'Неправильное размещение'));
            }
        })
        .catch(error => {
            console.error('Error deploying card:', error);
            alert('Ошибка при размещении карты');
        });
}

function moveUnit(gameId, unitId, targetX, targetY) {
    fetch(`/api/games/${gameId}/move-unit`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            side: currentPlayerSide,
            unit_id: unitId,
            x: targetX,
            y: targetY
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось переместить юнита: ' + (data.error || 'Неправильное действие'));
            }
        })
        .catch(error => {
            console.error('Error moving unit:', error);
        });
}

function attackUnit(gameId, attackerUnitId, targetUnitId) {
    const attackerEl = getUnitElementById(attackerUnitId);
    const targetEl = getUnitElementById(targetUnitId);

    const selectedIsAttacker = !!(selectedUnit && selectedUnit.unitId === attackerUnitId);
    const attackAlreadyUsed = !!(selectedIsAttacker && selectedUnit.hasAttacked);

    if (attackAlreadyUsed) {
        return;
    }

    const isArcherShot = !!(
        selectedIsAttacker &&
        selectedUnit.type === 'archer'
    );

    const isMeleeHit = !!(
        selectedIsAttacker &&
        selectedUnit.type !== 'archer'
    );

    const animation = isArcherShot
        ? animateArrowFlight(attackerEl, targetEl)
        : (isMeleeHit ? animateMeleeStrike(attackerEl, targetEl) : Promise.resolve());

    animation
        .then(() => fetch(`/api/games/${gameId}/attack-unit`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                side: currentPlayerSide,
                attacker_unit_id: attackerUnitId,
                target_unit_id: targetUnitId
            })
        }))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось атаковать: ' + (data.error || 'Ошибка'));
            }
        })
        .catch(error => {
            console.error('Error attacking unit:', error);
            alert('Ошибка при атаке');
        });
}

function attackWithBase(gameId, targetUnitId) {
    if (selectedBaseSide === currentPlayerSide && !selectedBaseCanAttack()) {
        return;
    }

    const baseEl = getSelectedBaseElement();
    const targetEl = getUnitElementById(targetUnitId);

    animateArrowFlight(baseEl, targetEl)
        .then(() => fetch(`/api/games/${gameId}/attack-base`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                side: currentPlayerSide,
                target_unit_id: targetUnitId
            })
        }))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось атаковать штабом: ' + (data.error || 'Ошибка'));
            }
        })
        .catch(error => {
            console.error('Error base attack:', error);
            alert('Ошибка при атаке штабом');
        });
}

function attackBase(gameId, targetSide, attackerUnitId) {
    const payload = {
        side: currentPlayerSide,
        target_side: targetSide
    };

    if (attackerUnitId) {
        payload.attacker_unit_id = attackerUnitId;
    }

    const targetBaseEl = document.querySelector(`.player-base[data-owner-side="${targetSide}"]`);
    const isBaseShot = !attackerUnitId;

    if (isBaseShot && selectedBaseSide === currentPlayerSide && !selectedBaseCanAttack()) {
        return;
    }

    const selectedIsAttacker = !!(attackerUnitId && selectedUnit && selectedUnit.unitId === attackerUnitId);
    if (selectedIsAttacker && selectedUnit.hasAttacked) {
        return;
    }

    const isArcherShot = !!(
        selectedIsAttacker &&
        selectedUnit.type === 'archer'
    );

    const isMeleeHit = !!(
        selectedIsAttacker &&
        selectedUnit.type !== 'archer'
    );

    const sourceEl = isBaseShot
        ? getSelectedBaseElement()
        : getUnitElementById(attackerUnitId);

    const animation = isBaseShot
        ? animateArrowFlight(sourceEl, targetBaseEl)
        : (isArcherShot ? animateArrowFlight(sourceEl, targetBaseEl) : (isMeleeHit ? animateMeleeStrike(sourceEl, targetBaseEl) : Promise.resolve()));

    animation
        .then(() => fetch(`/api/games/${gameId}/attack-base`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(payload)
        }))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось атаковать вражеский штаб: ' + (data.error || 'Ошибка'));
            }
        })
        .catch(error => {
            console.error('Error attacking enemy base:', error);
            alert('Ошибка при атаке штаба');
        });
}

function endTurn(gameId) {
    fetch(`/api/games/${gameId}/end-turn`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            side: currentPlayerSide
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateGameView(gameId);
            } else {
                alert('Не удалось завершить ход: ' + (data.error || 'Неправильное действие'));
            }
        })
        .catch(error => {
            console.error('Error ending turn:', error);
        });
}

function updateGameView(gameId) {
    fetch(`/api/games/${gameId}`)
        .then(response => response.json())
        .then(data => {
            if (data.current_player_side) {
                currentPlayerSide = data.current_player_side;
            }

            location.reload();
        })
        .catch(error => {
            console.error('Error updating game view:', error);
        });
}

function applyAllowedAttackCursor(el) {
    if (!el) return;
    el.style.cursor = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'36\' height=\'36\' viewBox=\'0 0 36 36\'%3E%3Ctext x=\'2\' y=\'28\' font-size=\'24\'%3E%E2%9A%94%EF%B8%8F%3C/text%3E%3C/svg%3E") 4 28, pointer';
}

function applyAllowedRangedAttackCursor(el) {
    if (!el) return;
    el.style.cursor = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'36\' height=\'36\' viewBox=\'0 0 36 36\'%3E%3Ctext x=\'2\' y=\'28\' font-size=\'24\'%3E%F0%9F%8F%B9%3C/text%3E%3C/svg%3E") 4 28, pointer';
}

function resetAllowedAttackCursor(el) {
    if (!el) return;
    el.style.removeProperty('cursor');
}

function applyBlockedAttackCursor(el) {
    if (!el) return;
    el.style.cursor = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'36\' height=\'36\' viewBox=\'0 0 36 36\'%3E%3Ctext x=\'2\' y=\'28\' font-size=\'24\'%3E%E2%9A%94%EF%B8%8F%3C/text%3E%3Cline x1=\'2\' y1=\'4\' x2=\'32\' y2=\'32\' stroke=\'%23ef4444\' stroke-width=\'3\'/%3E%3C/svg%3E") 4 28, not-allowed';
}

function applyBlockedRangedAttackCursor(el) {
    if (!el) return;
    el.style.cursor = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'36\' height=\'36\' viewBox=\'0 0 36 36\'%3E%3Ctext x=\'2\' y=\'28\' font-size=\'24\'%3E%F0%9F%8F%B9%3C/text%3E%3Cline x1=\'2\' y1=\'4\' x2=\'32\' y2=\'32\' stroke=\'%23ef4444\' stroke-width=\'3\'/%3E%3C/svg%3E") 4 28, not-allowed';
}

function resetBlockedAttackCursor(el) {
    if (!el) return;
    el.style.removeProperty('cursor');
}
