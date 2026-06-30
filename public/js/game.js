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

    // Добавляем обработчик для клеток штаба
    const baseCells = document.querySelectorAll('.player-base');
    baseCells.forEach(cell => {
        cell.addEventListener('click', function() {
            // Проверяем, есть ли выделенная карта
            const selectedCard = document.querySelector('.card.selected');
            if (selectedCard) {
                const cardType = selectedCard.getAttribute('data-card-type');

                // Получаем координаты штаба
                const baseX = parseInt(this.getAttribute('data-cell-x'));
                const baseY = parseInt(this.getAttribute('data-cell-y'));

                // Проверяем, что это ближайшая клетка к штабу (в пределах 1 клетки по X или Y)
                let isValid = false;
                if (baseX === 0 && baseY === 0) {
                    // Штаб игрока 1 - проверяем соседние клетки
                    isValid = isAdjacentToBase(0, 0, cardType);
                } else if (baseX === 4 && baseY === 2) {
                    // Штаб игрока 2 - проверяем соседние клетки
                    isValid = isAdjacentToBase(4, 2, cardType);
                }

                if (isValid) {
                    // Перемещаем карту на штаб
                    deployCardToBase(gameId, cardType, baseX, baseY);
                } else {
                    alert('Вы можете размещать карты только на клетках рядом со своим штабом!');
                }
            }
        });
    });
}

function isAdjacentToBase(baseX, baseY, cardType) {
    // Получаем все соседние клетки штаба
    const adjacentCells = getAdjacentCells(baseX, baseY);

    // Для простоты разрешаем размещение карт на всех соседних клетках
    // В реальной игре здесь нужно проверить, действительно ли это допустимая позиция

    // Проверяем, что карта может быть размещена рядом с штабом (в пределах поля)
    return true;
}

function getAdjacentCells(x, y) {
    const adjacent = [];

    // Соседние клетки (вверх, вниз, влево, вправо)
    if (x > 0) adjacent.push({x: x-1, y: y});
    if (x < 4) adjacent.push({x: x+1, y: y});
    if (y > 0) adjacent.push({x: x, y: y-1});
    if (y < 2) adjacent.push({x: x, y: y+1});

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
            type: type,
            x: x,
            y: y
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Card deployed successfully');
                // Обновление интерфейса
                updateGameView(gameId);

                // Снимаем выделение с карты
                const selectedCard = document.querySelector('.card.selected');
                if (selectedCard) {
                    selectedCard.classList.remove('selected');
                }
            } else {
                console.error('Failed to deploy card:', data.error);
            }
        })
        .catch(error => {
            console.error('Error deploying card:', error);
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
        })
        .catch(error => {
            console.error('Error updating game view:', error);
        });
}
