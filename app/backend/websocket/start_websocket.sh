#!/bin/bash
# WebSocket Server Startup Script for cPanel
# This script ensures only one instance runs at a time

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/server.pid"
SERVER_SCRIPT="$SCRIPT_DIR/server.php"
LOG_FILE="$SCRIPT_DIR/server.log"

# Check if server is already running
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE" | grep -oP '"pid":\K\d+')
    if ps -p $PID > /dev/null 2>&1; then
        echo "WebSocket server is already running (PID: $PID)"
        exit 0
    else
        # Stale PID file, remove it
        rm -f "$PID_FILE"
    fi
fi

# Start the server
nohup /usr/local/bin/php "$SERVER_SCRIPT" >> "$LOG_FILE" 2>&1 &

echo "WebSocket server started at $(date)"
exit 0
