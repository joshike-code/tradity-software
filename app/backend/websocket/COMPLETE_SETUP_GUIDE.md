# 🚀 WebSocket Server Setup Guide

## ⚠️ IMPORTANT: Web Interface Limitations

**The WebSocket server CANNOT be reliably started/stopped from the web dashboard on production servers.**

This is a fundamental limitation of:
- PHP execution in web contexts
- Apache/Nginx process restrictions  
- cPanel security policies

**Solution**: Use cron jobs for automatic management (recommended for production).

---

## 📋 Production Setup (cPanel) - RECOMMENDED

### Step 1: One-Time Configuration

1. **SSH into your cPanel server:**
   ```bash
   ssh username@your-server.com
   cd /home/username/test.affixglobal.live/app/backend/websocket
   ```

2. **Make scripts executable:**
   ```bash
   chmod +x start_websocket.sh stop_websocket.sh
   ```

3. **Test manual start:**
   ```bash
   ./start_websocket.sh
   ```

4. **Verify it's running:**
   ```bash
   cat server.pid
   tail -f server.log
   ```

### Step 2: Setup Auto-Start Cron Job

1. **Login to cPanel**
2. **Navigate to: Advanced → Cron Jobs**
3. **Add New Cron Job:**

   **Common Settings:** Every 5 minutes  
   **Command:**
   ```bash
   /bin/bash /home/username/test.affixglobal.live/app/backend/websocket/start_websocket.sh
   ```

4. **Save**

### How It Works:
- ✅ Cron runs every 5 minutes
- ✅ Checks if server is running
- ✅ Starts server only if stopped
- ✅ Server auto-recovers from crashes
- ✅ No manual intervention needed

---

## 🖥️ Development Setup (Windows/XAMPP)

### Option A: Manual Start (Recommended for Development)

1. **Open Command Prompt as Administrator**
2. **Navigate to websocket directory:**
   ```cmd
   cd C:\xampp\htdocs\tradity-backend\websocket
   ```
3. **Run the batch file:**
   ```cmd
   start_websocket.bat
   ```

### Option B: Task Scheduler (Windows Auto-Start)

1. Open **Task Scheduler**
2. Create **Basic Task**:
   - Name: `Tradity WebSocket Server`
   - Trigger: **At startup**
   - Action: **Start a program**
   - Program: `C:\xampp\htdocs\tradity-backend\websocket\start_websocket.bat`
3. **Save**

---

## 🎛️ Admin Dashboard Control

The dashboard provides **monitoring and manual control**, but with limitations:

### ✅ What Works:
- **GET `/api/admin/manage-server`** - Check server status (always works)
- **POST `/api/admin/manage-server?action=stop`** - Stop server (usually works)

### ⚠️ What May Fail:
- **POST `/api/admin/manage-server?action=start`** - Start server (unreliable on production)
  - On cPanel: Creates trigger file for cron to pick up
  - On Windows dev: May work with batch file

### Recommended Workflow:
1. **Check Status** via dashboard ✅
2. **Stop Server** via dashboard if needed ✅
3. **Start Server** via SSH or wait for cron (production) ⏳

---

## 🔧 Manual Control Commands

### Linux/cPanel:

**Start:**
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
./start_websocket.sh
```

**Stop:**
```bash
cd /home/username/test.affixglobal.live/app/backend/websocket
./stop_websocket.sh
```

**Check Status:**
```bash
cat server.pid
ps aux | grep server.php
tail -f server.log
```

### Windows/XAMPP:

**Start:**
```cmd
cd C:\xampp\htdocs\tradity-backend\websocket
start_websocket.bat
```

**Stop:**
```cmd
# Find PHP process in Task Manager
tasklist | findstr php.exe
# Kill the WebSocket server process (note the PID from server.pid)
taskkill /PID [PID_NUMBER] /F
```

**Check Status:**
```cmd
type server.pid
type server.log
```

---

## 🐛 Troubleshooting

### Server Not Starting on cPanel?

1. **Check PHP CLI path:**
   ```bash
   which php
   # Should show: /usr/local/bin/php or similar
   ```

2. **Test server directly:**
   ```bash
   /usr/local/bin/php server.php
   # Watch for errors
   ```

3. **Check permissions:**
   ```bash
   ls -la start_websocket.sh
   # Should show: -rwxr-xr-x (executable)
   ```

4. **Verify cron job:**
   - Check cPanel → Cron Jobs
   - Ensure correct path
   - Check cron email for errors

### Server Keeps Stopping?

1. **Remove stop signal file:**
   ```bash
   rm -f server.stop
   ```

2. **Check for errors in log:**
   ```bash
   tail -100 server.log
   ```

### Dashboard Shows "Not Running" But It Is?

1. **Check PID file:**
   ```bash
   cat server.pid
   ```

2. **Verify process:**
   ```bash
   ps aux | grep [PID]
   ```

3. **Refresh dashboard** - Status updates every request

---

## 📊 Monitoring

### Via Dashboard:
- Navigate to **Admin Panel → Server Management**
- Shows: Running status, PID, uptime, last log entry
- Updates on page refresh

### Via SSH:
```bash
# Real-time log monitoring
tail -f /home/username/.../websocket/server.log

# Process check
ps aux | grep server.php

# Resource usage
top -p [PID]
```

---

## 🎯 Best Practices

### For Developers:
- ✅ Start server manually via command line
- ✅ Monitor logs during development
- ✅ Use dashboard for status checks only

### For Production (Customers):
- ✅ Setup cron job once during installation
- ✅ Server runs automatically 24/7
- ✅ Dashboard for monitoring only
- ✅ SSH for manual control if needed

### For Software Distributors:
- ✅ Include cron setup in installation guide
- ✅ Provide SSH instructions for customers
- ✅ Set realistic expectations about dashboard control
- ✅ Offer support for cron configuration

---

## 🆘 Support

If server won't start after following this guide:

1. **Collect debug info:**
   ```bash
   # System info
   uname -a
   php -v
   which php
   
   # Server status
   cat server.pid
   tail -50 server.log
   
   # Permissions
   ls -la start_websocket.sh server.php
   ```

2. **Check common issues:**
   - PHP CLI not installed
   - Wrong PHP path in scripts
   - Permissions not set (chmod +x)
   - Cron job not configured
   - Old processes still running

3. **Contact support with:**
   - Output from step 1
   - Hosting provider (cPanel/Plesk/VPS)
   - PHP version
   - Error messages from logs

---

## 📚 Additional Resources

- [cPanel Cron Jobs Documentation](https://docs.cpanel.net/cpanel/advanced/cron-jobs/)
- [PHP CLI vs Web Server](https://www.php.net/manual/en/features.commandline.php)
- [Process Management on Linux](https://www.digitalocean.com/community/tutorials/how-to-use-ps-kill-and-nice-to-manage-processes-in-linux)
