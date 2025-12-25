# 📱 Quick Setup Card - WebSocket Server

## 🎯 Choose Your Setup Method

### ⚡ Option A: No SSH Required (Easiest)

**Perfect for users without SSH access!**

1. **Open your browser** and go to:
   ```
   https://yourdomain.com/app/backend/websocket/setup.php
   ```

2. **Follow the on-screen wizard:**
   - Click "Make Scripts Executable"
   - Click "Test Start Server"
   - Setup cron job (copy provided command)

3. **Delete setup.php** when done (security!)

**That's it!** Server will run automatically via cron job.

---

### ⚡ Option B: SSH Setup (Advanced Users)

**If you have SSH access and prefer command line:**

#### 1️⃣ SSH Commands (One Time Only)
```bash
cd /home/USERNAME/DOMAIN/app/backend/websocket
chmod +x start_websocket.sh stop_websocket.sh
./start_websocket.sh
```
*Replace USERNAME and DOMAIN with your actual values*

#### 2️⃣ cPanel Cron Job (Set and Forget)
1. Login to cPanel
2. Go to **Advanced → Cron Jobs**
3. Add cron job:
   - **Interval**: Every 5 minutes
   - **Command**: 
     ```
     /bin/bash /home/USERNAME/DOMAIN/app/backend/websocket/start_websocket.sh
     ```

---

## ✅ After Setup

Server will now:
- ✅ Start automatically
- ✅ Restart if it crashes
- ✅ Run 24/7 without intervention

---

## 🎛️ Dashboard Control

**What the dashboard can do:**
- ✅ View server status
- ✅ Stop server
- ⚠️ Start command (relies on cron)

**Important:** Starting via dashboard may take up to 5 minutes (next cron run).

---

## 🔧 Manual Control (When Needed)

**Start Server:**
```bash
cd /home/USERNAME/DOMAIN/app/backend/websocket
./start_websocket.sh
```

**Stop Server:**
```bash
cd /home/USERNAME/DOMAIN/app/backend/websocket
./stop_websocket.sh
```

**Check Status:**
```bash
cat server.pid
tail -f server.log
```

---

## ⚠️ Common Issues

### "Permission Denied" Error
```bash
chmod +x start_websocket.sh stop_websocket.sh
```

### Server Won't Start
```bash
# Check PHP path
which php

# Test manually
php server.php
```

### Cron Not Working
- Verify cron job path is correct
- Check cPanel email for cron errors
- Ensure start_websocket.sh is executable

---

## 📞 Need Help?

1. Check `server.log` file for errors
2. Verify cron job is running (cPanel → Cron Jobs)
3. Contact support with log file contents

---

## 💡 Pro Tips

- ✅ Setup cron job once, forget about it
- ✅ Server recovers automatically from crashes
- ✅ Use dashboard for monitoring
- ✅ Use SSH for troubleshooting
- ❌ Don't rely on dashboard start button in production

---
