#!/bin/bash
# WebSocket Server Stop Script for cPanel

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/server.pid"
STOP_FILE="$SCRIPT_DIR/server.stop"

# Create stop signal file
echo "$(date +%s)" > "$STOP_FILE"

# Check if PID file exists
if [ ! -f "$PID_FILE" ]; then
    echo "WebSocket server is not running (no PID file)"
    rm -f "$STOP_FILE"
    exit 0
fi

# Get PID from file
PID=$(cat "$PID_FILE" | grep -oP '"pid":\K\d+')

if [ -z "$PID" ]; then
    echo "Invalid PID file"
    rm -f "$PID_FILE" "$STOP_FILE"
    exit 1
fi

# Check if process is running
if ! ps -p $PID > /dev/null 2>&1; then
    echo "Process $PID is not running"
    rm -f "$PID_FILE" "$STOP_FILE"
    exit 0
fi

# Wait for graceful shutdown (server checks stop file)
echo "Sending stop signal to PID $PID..."
sleep 3

# Check if stopped gracefully
if ! ps -p $PID > /dev/null 2>&1; then
    echo "WebSocket server stopped gracefully"
    rm -f "$PID_FILE" "$STOP_FILE"
    exit 0
fi

# Force kill if still running
echo "Force stopping PID $PID..."
kill -9 $PID 2>/dev/null

sleep 1

# Final check
if ps -p $PID > /dev/null 2>&1; then
    echo "Failed to stop WebSocket server"
    exit 1
else
    echo "WebSocket server stopped"
    rm -f "$PID_FILE" "$STOP_FILE"
    exit 0
fi
