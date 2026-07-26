#!/bin/bash

# AIAgentV6 vs AIAgentV3Release - Series Test
# Runs 5 games alternating which bot is player_1

set -e

GAME_API_URL="${GAME_API_BASE_URL:-http://localhost:8000}"
RESULTS_FILE="v6-vs-v3-results-$(date +%Y%m%d-%H%M%S).txt"

echo "AIAgentV6 vs AIAgentV3Release - 5 Game Series"
echo "=============================================="
echo "API URL: $GAME_API_URL"
echo "Results: $RESULTS_FILE"
echo ""

cd bot-service

{
    echo "Series started at $(date)"
    echo "API Base URL: $GAME_API_URL"
    echo "=============================================="
    echo ""
    
    P1_WINS=0
    P2_WINS=0
    
    for GAME_NUM in {1..5}; do
        echo "[Game $GAME_NUM/5]"
        
        if [ $((GAME_NUM % 2)) -eq 1 ]; then
            # V6 as player_1
            P1_STRATEGY="ai_agent_v6"
            P2_STRATEGY="ai_agent_v3_release"
            P1_NAME="V6"
            P2_NAME="V3Release"
            echo "Setup: $P1_NAME (player_1) vs $P2_NAME (player_2)"
        else
            # V3Release as player_1
            P1_STRATEGY="ai_agent_v3_release"
            P2_STRATEGY="ai_agent_v6"
            P1_NAME="V3Release"
            P2_NAME="V6"
            echo "Setup: $P1_NAME (player_1) vs $P2_NAME (player_2)"
        fi
        
        # Run the game and capture output
        if OUTPUT=$(GAME_API_BASE_URL="$GAME_API_URL" php bin/bot-vs-bot.php \
            --p1-strategy="$P1_STRATEGY" \
            --p2-strategy="$P2_STRATEGY" \
            --p1-name="$P1_NAME" \
            --p2-name="$P2_NAME" \
            --max-turns=500 2>&1); then
            
            # Extract winner from output
            if echo "$OUTPUT" | grep -q "winner_side=player_1"; then
                WINNER="player_1 ($P1_NAME)"
                if [ "$P1_NAME" = "V6" ]; then
                    ((P1_WINS++))
                else
                    ((P2_WINS++))
                fi
            elif echo "$OUTPUT" | grep -q "winner_side=player_2"; then
                WINNER="player_2 ($P2_NAME)"
                if [ "$P2_NAME" = "V6" ]; then
                    ((P1_WINS++))
                else
                    ((P2_WINS++))
                fi
            else
                WINNER="unknown (timeout?)"
            fi
            
            echo "Result: $WINNER"
            echo "Output: $OUTPUT" | head -5
            echo ""
        else
            echo "ERROR: Game $GAME_NUM failed"
            echo "$OUTPUT"
            exit 1
        fi
        
        # Small delay between games
        if [ $GAME_NUM -lt 5 ]; then
            echo "Waiting 2 seconds before next game..."
            sleep 2
        fi
    done
    
    echo ""
    echo "=============================================="
    echo "Series Results"
    echo "=============================================="
    echo "V6 Wins: $P1_WINS/5"
    echo "V3Release Wins: $P2_WINS/5"
    echo ""
    
    RATIO=$(echo "scale=2; $P1_WINS * 100 / 5" | bc)
    echo "V6 Win Rate: ${RATIO}%"
    echo ""
    
    if [ $P1_WINS -ge 2 ] && [ $P1_WINS -le 3 ]; then
        echo "✅ Result in expected range (40-60% win rate)"
    elif [ $P1_WINS -lt 2 ]; then
        echo "⚠️  V6 underperforming (win rate < 40%)"
    else
        echo "⚠️  V6 overperforming (win rate > 60%)"
    fi
    
    echo ""
    echo "Series finished at $(date)"
    
} | tee "$RESULTS_FILE"

echo ""
echo "Full results saved to: $RESULTS_FILE"
