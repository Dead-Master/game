(function () {
    const root = document.getElementById('watch-root');
    if (!root) return;

    const gameId = root.getAttribute('data-game-id');
    const wsKey = root.getAttribute('data-ws-key');
    const wsHost = root.getAttribute('data-ws-host');
    const wsPort = Number(root.getAttribute('data-ws-port') || 8080);
    const wsScheme = root.getAttribute('data-ws-scheme') || 'http';

    const boardEl = document.getElementById('board');

    const statusEl = document.getElementById('m-status');
    const turnEl = document.getElementById('m-turn');
    const roundEl = document.getElementById('m-round');
    const sideEl = document.getElementById('m-side');
    const updatedEl = document.getElementById('m-updated');
    const p1HpEl = document.getElementById('p1-hp');
    const p2HpEl = document.getElementById('p2-hp');

    function buildEmptyBoard() {
        boardEl.innerHTML = '';
        for (let y = 2; y >= 0; y--) {
            for (let x = 0; x < 5; x++) {
                const cell = document.createElement('div');
                cell.className = 'cell';
                cell.setAttribute('data-x', String(x));
                cell.setAttribute('data-y', String(y));
                cell.innerHTML = `<span class="coord">${x},${y}</span>`;
                boardEl.appendChild(cell);
            }
        }
    }

    function cellByXY(x, y) {
        return boardEl.querySelector(`.cell[data-x="${x}"][data-y="${y}"]`);
    }

    function renderState(payload) {
        const game = payload.game || {};
        const players = payload.players || [];
        const units = (payload.units || []).filter((u) => u.state === 'board');

        statusEl.textContent = game.status ?? '—';
        turnEl.textContent = String(game.current_turn ?? '—');
        roundEl.textContent = String(game.round_number ?? '—');
        sideEl.textContent = payload.current_player_side ?? '—';
        updatedEl.textContent = new Date().toLocaleTimeString();

        const p1 = players.find((p) => p.side === 'player_1');
        const p2 = players.find((p) => p.side === 'player_2');
        p1HpEl.textContent = String(p1?.base_hp ?? '—');
        p2HpEl.textContent = String(p2?.base_hp ?? '—');

        buildEmptyBoard();

        const b1 = cellByXY(0, 0);
        if (b1) {
            b1.innerHTML += `<div class="base u1">🏰 P1 (HP: ${p1?.base_hp ?? '—'})</div>`;
        }

        const b2 = cellByXY(4, 2);
        if (b2) {
            b2.innerHTML += `<div class="base u2">🏰 P2 (HP: ${p2?.base_hp ?? '—'})</div>`;
        }

        units.forEach((u) => {
            if (u.position_x === null || u.position_y === null) return;
            const cell = cellByXY(u.position_x, u.position_y);
            if (!cell) return;

            const cls = u.owner_side === 'player_1' ? 'u1' : 'u2';
            cell.innerHTML += `<div class="${cls}">⚔ ${u.type} (HP:${u.hp}, ATK:${u.attack_power})</div>`;
        });
    }

    async function loadState() {
        try {
            const response = await fetch(`/api/games/${gameId}/live-state`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (!payload.success) {
                throw new Error('API returned success=false');
            }

            renderState(payload);
        } catch (error) {
            updatedEl.textContent = `ошибка: ${error.message}`;
        }
    }

    function connectWs() {
        if (!window.Pusher) {
            console.warn('Pusher script is not loaded');
            return;
        }

        const forceTLS = wsScheme === 'https';
        const pusher = new window.Pusher(wsKey, {
            wsHost: wsHost,
            wsPort: wsPort,
            wssPort: wsPort,
            forceTLS: forceTLS,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            cluster: 'mt1',
        });

        const channel = pusher.subscribe(`game.${gameId}`);

        channel.bind('game.updated', function () {
            loadState();
        });

        pusher.connection.bind('connected', function () {
            console.log('watch ws connected');
        });

        pusher.connection.bind('disconnected', function () {
            console.log('watch ws disconnected');
        });

        pusher.connection.bind('error', function (err) {
            console.error('watch ws error', err);
        });
    }

    buildEmptyBoard();
    loadState(); // initial snapshot
    connectWs();
})();
