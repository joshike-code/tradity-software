# WebSocket Server Management for cPanel

## Setup Instructions (One-Time Setup)

### Step 1: Make Scripts Executable
SSH into your cPanel server and run:
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
chmod +x start_websocket.sh stop_websocket.sh
```

### Step 2: Setup Cron Job to Keep Server Running

1. **Login to cPanel**
2. **Go to "Cron Jobs"**
3. **Add New Cron Job:**
   - **Common Settings**: Every 5 minutes
   - **Command**:
     ```bash
     /bin/bash /home/username/test.affixglobal.live/app/backend/websocket/start_websocket.sh
     ```
   - This will check every 5 minutes if the server is running, and start it if not

### Step 3: Manual Control (Optional)

#### Start Server Manually:
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
./start_websocket.sh
```

#### Stop Server Manually:
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
./stop_websocket.sh
```

#### Check Server Status:
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
cat server.pid
cat server.log
```

---

## How It Works

1. **Cron job runs every 5 minutes** → Checks if WebSocket server is running
2. **If server crashed** → Automatically restarts it
3. **If server is running** → Does nothing (exits immediately)
4. **Admin can still control** → Via dashboard (see below)

---

## Dashboard Integration (Admin Control)

The admin dashboard can still **trigger** start/stop via the existing API, but now it works differently:

### **Start Server:**
POST `/api/admin/manage-server?action=start`
- Creates a trigger file that tells the cron job to start the server
- Responds immediately with "Start command issued"
- Cron job picks it up within 5 minutes

### **Stop Server:**
POST `/api/admin/manage-server?action=stop`
- Creates `server.stop` file
- Server gracefully shuts down on next loop iteration
- Responds immediately with "Stop command issued"

### **Status Check:**
GET `/api/admin/manage-server`
- Checks if PID file exists and process is running
- Returns real-time status

---

## Benefits

✅ **Reliable** - Cron ensures server stays running
✅ **cPanel-friendly** - No process spawning from web requests
✅ **Auto-recovery** - Server restarts if it crashes
✅ **Graceful shutdown** - Proper cleanup on stop
✅ **Production-ready** - Used by professional platforms

---

## Troubleshooting

### Server not starting?
```bash
# Check logs
tail -f /home/username/test.affixglobal.live/app/backend/websocket/server.log

# Check if PHP CLI works
/usr/local/bin/php -v

# Test server manually
cd /home/username/test.affixglobal.live/app/backend/websocket
/usr/local/bin/php server.php
```

### Server keeps stopping?
- Check for `server.stop` file in websocket directory
- Remove it: `rm -f websocket/server.stop`

### Permission denied?
```bash
chmod +x websocket/start_websocket.sh
chmod +x websocket/stop_websocket.sh
chmod 755 websocket/
```

---

## Alternative: Supervisor (Advanced)

For even more reliability, install Supervisor (if cPanel allows):
```ini
[program:tradity-websocket]
command=/usr/local/bin/php /home/username/test.affixglobal.live/app/backend/websocket/server.php
directory=/home/username/test.affixglobal.live/app/backend/websocket
autostart=true
autorestart=true
user=username
redirect_stderr=true
stdout_logfile=/home/username/test.affixglobal.live/app/backend/websocket/server.log
```
