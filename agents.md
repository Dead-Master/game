🎯 Project Overview
Laravel-based hotseat browser card/board strategy game (KKI-style). Two players take turns on a single device. Game state is persisted in SQLite and synced via AJAX-driven API responses. No authentication or online matchmaking; turn tracking relies on Laravel sessions. Frontend uses jQuery + Blade for rendering, with Tailwind CSS v4 for styling. Core business logic is isolated in App\Services\GameManager.

🛠 Tech Stack & Environment
Layer
Technology
Runtime
PHP 8.3 (strict types preferred)
Framework
Laravel 13.17.0
Database
SQLite (database/database.sqlite)
HTTP/Async
guzzlehttp/guzzle:7.12.3, Symfony Mailer, Monolog
Testing
PHPUnit, mockery/mockery:1.6.12
Frontend
Blade templates, jQuery 3.7.1 (AJAX sync), Tailwind CSS 4.0.0 (@tailwindcss/vite), Vite 8.0.0
Package Managers
Composer, npm
Other
laravel/tinker:v3.0.2, dragonmantank/cron-expression:v3.6.0, symfony/console:v7.4.14

📐 Architecture & Patterns
MVC: Standard Laravel routing → Controller → Service/Model → View
Service Layer: All game mechanics, combat, supply generation, and card parsing live in App\Services\GameManager. Controllers only handle HTTP deserialization, session management, and JSON/View responses.
State Management:
Backend: Eloquent models + SQLite
Frontend: jQuery maintains local UI state (gameState, selectedCardIdx, selectedUnit) and re-renders on AJAX success
Turn Tracking: session('current_player_side') in Hotseat mode
API Safety: All /api/* routes bypass CSRF verification via app/Http/Middleware/VerifyCsrfToken.php::$except = ['api/*']
No Auth/Permissions: Game access is open; hotseat relies purely on session state and UI gating.

🗄 Core Database Schema
Table
Key Fields
Notes
games
id, status, current_turn, round_number, grid_state (JSON), player_1_name, player_2_name
Game session container
game_players
id, game_id FK, side (enum), base_hp, base_attack, supply_income, supplies_current, hand (JSON), deck (JSON)
Per-player state
units
id, game_id FK, owner_id FK, type, max_hp, hp, attack_power, movement_points, position_x/y, state (hand/board/graveyard), is_active_turn
Board entities

🎮 Game Rules & Mechanics Summary
Board: Horizontal 5×3 grid. Player 1 base at (2,0), Player 2 base at (2,4) or vice versa (configurable in GamePlayer::getPosition()). Bases render as "штаб" with color-coded player tags (red/blue).
Deployment: Cards can only be placed on cells adjacent to the active player's base.
Supplies: Generated per turn. Base income = 1. On even turns, +1 bonus. Unspent supplies do not roll over.
Hand Management: Max 6 cards. Draw +1 at start of turn. Excess removed FIFO.
Unit Types & Stats: | Type | Cost | HP | Atk | Movement | Special | |------|------|----|-----|----------|---------| | Archer | 3 | 2 | 1 | 1 (any dir) | Ranged strike, no counter-attack | | Berserker | 4 | 9 | 4 | 1 (orth only) | No counter-attack | | Infantry | 2 | 5 | 2 | 1 (any dir) | Counts for counter-attacks | | Scout | 1 | 3 | 1 | 2 steps/turn (orth) | Counts for counter-attacks |
Win Condition: Reduce enemy base HP to 0.
Turn Flow: Deploy → Move → Attack/Combat → End Turn.

🔌 API & Data Flow
All endpoints return JSON unless explicitly viewing a Blade template.
Method
Path
Purpose
Payload / Response
GET
/
Landing page (hotseat setup form)
HTML
POST
/api/games
Create game & initialize decks
{ player_1_name, player_2_name } → { game_id }
GET
/api/games/{id}
Sync full game state
Full JSON (players, units, supplies, turn)
POST
/api/games/{game}/deploy-card
Place card on board
{ side, cell_x, cell_y, type } → success flag
POST
/api/games/{game}/move-unit
Move or initiate combat
{ unit_id, target_x, target_y }
POST
/api/games/{game}/end-turn
Switch active player & refresh supplies/hand
Empty body → success JSON
Session Flow (Hotseat):``` php
session(['current_player_side' => 'player_1']); // On game start
// Each action validates session, then flips it:
session(['current_player_side' => $nextSide]);
```

 
💻 Frontend Integration
Routing: Blade views only for initial page loads (/, /game/{id}). Everything else is AJAX-driven.
State Sync: setInterval(fetchGameState, 3000) polls /api/games/{id}. Local jQuery state (gameState) drives rendering.
Rendering: Manual DOM manipulation via jQuery. No virtual DOM or frameworks. Tailwind v4 classes applied inline/directly in Blade/JS templates.
Interaction Model:
Select card from hand → highlights deployment zones on grid
Click cell → validates adjacency/cost/occupancy → AJAX deploy-card
Select unit on board → highlights valid move range → click target → AJAX move-unit (triggers combat if enemy occupied)
#btn-end-turn → AJAX end-turn → refreshes grid/hand/supply UI
 
⚙️ Development Conventions
PHP: Use strict types (declare(strict_types=1);) in new files. Prefer value objects or readonly classes for state containers.
Eloquent: Direct model usage is acceptable per architecture. Avoid over-engineering repositories unless scale demands it.
JSON Casting: Always cast hand, deck, grid_state as arrays via $casts.
Session Safety: Never assume session('current_player_side') exists. Validate before use; return 403 if missing.
API Responses: Consistent shape: ['message' => string, 'success' => bool, data?: mixed] or full model JSON for sync endpoints.
Frontend JS: Isolate game loop logic in named functions. Keep jQuery selectors cached. Never bypass backend validation on the client side.
Testing: Mock Eloquent models with mockery/mockery. Use PHPUnit datasets for card/combat scenarios.
 
🤖 AI Agent Guidelines (Cursor / Copilot / Manual)
Always follow Laravel 13 & PHP 8.3 syntax. No newer features unless explicitly requested.
Business logic never lives in Controllers or Views. Route it to App\Services\GameManager or dedicated domain services.
When modifying the board/grid:
Keep coordinates 0-indexed: X ∈ [0, 4], Y ∈ [0, 2] (horizontal 5×3)
Bases render with color tags: #e53e3e (P1 red), #4299e1 (P2 blue)
Grid lines must be visible (border, gap, or CSS grid outlines)
API Routes: Must skip CSRF. Add to VerifyCsrfToken::$except if adding new /api/* paths.
Frontend Edits: Only modify UI state rendering, AJAX handlers, and Tailwind classes. Never duplicate game rules in JS.
Snippets: Show minimal context (// ... existing code ...), 3+ lines before/after change. No diff markers. Language tags mandatory.
Database Changes: Always include up/down migration. Update models & casts accordingly. Run php artisan migrate mentally in instructions if schema changes.
Security/Validation: Client-side is UX-only. Server validates costs, adjacency, movement rules, hand limits, and turn order.
 
🚀 Quick Commands``` bash
# Clear route/cache after changes
php artisan route:clear && php artisan cache:clear

# Run tests
vendor/bin/phpunit

# Serve locally (SQLite auto-detects database.sqlite)
php artisan serve

# Install frontend deps
npm install && npm run build
```
