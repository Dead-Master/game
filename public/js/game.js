// JavaScript для игры

document.addEventListener('DOMContentLoaded', function() {
    // Получаем ID игры из URL или из DOM элемента
    const gameId = getGameIdFromUrl();

    if (!gameId) {
        console.error('Game ID not found');
        return;
    }

    // Инициализация игрового поля
    initializeGameField(gameId);

    // Привязка событий
    setupEventListeners(gameId);
});

function getGameIdFromUrl() {
    // Пытаемся получить ID игры из URL
    const pathParts = window.location.pathname.split('/');
    const gameIdIndex = pathParts.indexOf('game') + 1;

    if (gameIdIndex > 0 && pathParts[gameIdIndex]) {
        return pathParts[gameIdIndex];
    }

    // Если не нашли в URL, проверяем data-атрибуты на странице
    const gameElement = document.querySelector('[data-game-id]');
    if (gameElement) {
        return gameElement.getAttribute('data-game-id');
    }

    return null;
}

function initializeGameField(gameId) {
    // Инициализация игрового поля (если нужно)
    console.log(`Initializing game field for game ID: ${gameId}`);
}

function setupEventListeners(gameId) {
    // Привязка событий к элементам
    const deployCardButtons = document.querySelectorAll('[data-deploy-card]');

    deployCardButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const cardType = this.getAttribute('data-card-type');
            const cellX = parseInt(this.getAttribute('data-cell-x'));
            const cellY = parseInt(this.getAttribute('data-cell-y'));

            deployCard(gameId, cardType, cellX, cellY);
        });
    });

    const moveUnitButtons = document.querySelectorAll('[data-move-unit]');

    moveUnitButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const unitId = parseInt(this.getAttribute('data-unit-id'));
            const targetX = parseInt(this.getAttribute('data-target-x'));
            const targetY = parseInt(this.getAttribute('data-target-y'));

            moveUnit(gameId, unitId, targetX, targetY);
        });
    });

    // Добавляем обработчик для выделения карт
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('click', function() {
            // Удаляем выделение с других карт
            cards.forEach(c => c.classList.remove('selected'));

            // Добавляем выделение к нажатой карте
            this.classList.add('selected');
        });
    });

    // Добавляем обработчик для клеток игрового поля
    const cells = document.querySelectorAll('.cell');
    cells.forEach(cell => {
        cell.addEventListener('click', function() {
            // Проверяем, есть ли выделенная карта
            const selectedCard = document.querySelector('.card.selected');
            if (selectedCard) {
                const cardType = selectedCard.getAttribute('data-card-type');
                const cellX = parseInt(this.getAttribute('data-x'));
                const cellY = parseInt(this.getAttribute('data-y'));

                // Проверяем, можно ли разместить карту в этой клетке
                // Для первого игрока (красных) проверяем соседние клетки с штабом
                const player1BaseX = 0;
                const player1BaseY = 0;

                // Получаем все соседние клетки штаба
                const adjacentCells = getAdjacentCells(player1BaseX, player1BaseY);

                // Проверяем, является ли выбранная клетка соседней к штабу
                let isValid = false;
                for (let i = 0; i < adjacentCells.length; i++) {
                    if (adjacentCells[i].x === cellX && adjacentCells[i].y === cellY) {
                        isValid = true;
                        break;
                    }
                }

                if (isValid) {
                    // Перемещаем карту на выбранную клетку
                    deployCard(gameId, cardType, cellX, cellY);

                    // Снимаем выделение с карты
                    selectedCard.classList.remove('selected');
                } else {
                    alert('Вы можете размещать карты только на соседних клетках с вашим штабом!');
                }
            }
        });
    });
}
function getAdjacentCells(x, y) {
    const adjacent = [];

    // Соседние клетки (вверх, вниз, влево, вправо и диагонали)
    for (let dx = -1; dx <= 1; dx++) {
        for (let dy = -1; dy <= 1; dy++) {
            // Пропускаем центральную клетку (штаб)
            if (dx === 0 && dy === 0) continue;

            const nx = x + dx;
            const ny = y + dy;

            // Проверяем границы поля
            if (nx >= 0 && nx < 5 && ny >= 0 && ny < 3) {
                adjacent.push({x: nx, y: ny});
            }
        }
    }

    return adjacent;
}

function deployCard(gameId, type, x, y) {
    // Получаем текущего игрока из сессии (для простоты предположим, что это игрок 1)
    const currentPlayerSide = 'player_1'; // В реальном приложении нужно получить из состояния игры

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
                console.log('Card deployed successfully');
                // Обновление интерфейса
                updateGameView(gameId);
            } else {
                console.error('Failed to deploy card:', data.error);
                alert('Не удалось разместить карту: ' + (data.message || 'Неправильное размещение'));
            }
        })
        .catch(error => {
            console.error('Error deploying card:', error);
            alert('Ошибка при размещении карты');
        });
}

function deployCardToBase(gameId, type, x, y) {
    // Здесь мы вызываем API для размещения карты на штабе
    fetch(`/api/games/${gameId}/deploy-card`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            type: type,
            x: x,
            y: y
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Card deployed to base successfully');
                // Обновление интерфейса
                updateGameView(gameId);

                // Снимаем выделение с карты
                const selectedCard = document.querySelector('.card.selected');
                if (selectedCard) {
                    selectedCard.classList.remove('selected');
                }
            } else {
                console.error('Failed to deploy card to base:', data.error);
            }
        })
        .catch(error => {
            console.error('Error deploying card to base:', error);
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
            unit_id: unitId,
            x: targetX,
            y: targetY
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Unit moved successfully');
                // Обновление интерфейса
                updateGameView(gameId);
            } else {
                console.error('Failed to move unit:', data.error);
            }
        })
        .catch(error => {
            console.error('Error moving unit:', error);
        });
}

function endTurn(gameId) {
    fetch(`/api/games/${gameId}/end-turn`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Turn ended successfully');
                // Обновление интерфейса
                updateGameView(gameId);
            } else {
                console.error('Failed to end turn:', data.error);
            }
        })
        .catch(error => {
            console.error('Error ending turn:', error);
        });
}

function updateGameView(gameId) {
    // Обновление отображения игры после действий
    fetch(`/api/games/${gameId}`)
        .then(response => response.json())
        .then(data => {
            // Здесь обновляем интерфейс на основе полученных данных
            console.log('Updated game data:', data);
            // Для простоты перезагружаем страницу, но в реальном приложении лучше обновить только нужные элементы
            location.reload();
        })
        .catch(error => {
            console.error('Error updating game view:', error);
        });
}
