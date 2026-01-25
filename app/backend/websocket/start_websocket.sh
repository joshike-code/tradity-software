#!/bin/bash
# WebSocket Server Startup Script for cPanel
# This script ensures only one instance runs at a time

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/server.pid"
SERVER_SCRIPT="$SCRIPT_DIR/server.php"
LOG_FILE="$SCRIPT_DIR/server.log"

# Auto-detect PHP CLI binary (try multiple common locations)
PHP_BIN=""
for php_path in /usr/local/bin/php /usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php /opt/cpanel/ea-php80/root/usr/bin/php $(which php 2>/dev/null); do
    if [ -x "$php_path" ]; then
        PHP_BIN="$php_path"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo "ERROR: Could not find PHP CLI binary. Tried common paths but none found."
    echo "Please set PHP path manually in start_websocket.sh"
    exit 1
fi

echo "Using PHP: $PHP_BIN"
echo "PHP Version: $($PHP_BIN -v | head -n 1)"

# Check if server is already running
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE" | grep -oP '"pid":\K\d+' 2>/dev/null)
    if [ -n "$PID" ] && ps -p $PID > /dev/null 2>&1; then
        echo "WebSocket server is already running (PID: $PID)"
        exit 0
    else
        # Stale PID file, remove it
        echo "Removing stale PID file"
        rm -f "$PID_FILE"
    fi
fi

# Verify server script exists
if [ ! -f "$SERVER_SCRIPT" ]; then
    echo "ERROR: Server script not found at: $SERVER_SCRIPT"
    exit 1
fi

# Start the server
echo "Starting WebSocket server..."
nohup "$PHP_BIN" "$SERVER_SCRIPT" >> "$LOG_FILE" 2>&1 &
NOHUP_PID=$!

# Wait a moment and verify it started
sleep 2

if ps -p $NOHUP_PID > /dev/null 2>&1; then
    echo "✓ WebSocket server started successfully at $(date)"
    echo "✓ Process ID: $NOHUP_PID"
else
    echo "✗ Failed to start WebSocket server. Check $LOG_FILE for errors."
    tail -n 20 "$LOG_FILE"
    exit 1
fi

exit 0
