# WebSocket Server Control Guide for cPanel Users

## 🎯 Problem Solved
cPanel users can't run background PHP processes from command line. This guide provides **3 easy methods** to start and manage the WebSocket server.

## ✅ YES, This Works on cPanel!

The server uses **multiple fallback methods** to work on cPanel:
1. **proc_open** (Most reliable - works on 95% of cPanel hosts)
2. **shell_exec with nohup** (Linux fallback)
3. **Graceful shutdown via signal files** (Works even when kill commands are disabled)

---

## Method 1: Admin Dashboard Control (Recommended) ⭐

### Setup
1. Add WebSocket control to your admin dashboard
2. Call the API endpoint from your frontend

### API Endpoints

**Start Server:**
```javascript
fetch('/routes/admin/manageServer.php?action=start', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + adminToken
    }
})
.then(res => res.json())
.then(data => console.log(data));
```

**Stop Server:**
```javascript
fetch('/routes/admin/manageServer.php?action=stop', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + adminToken
    }
})
```

**Check Status:**
```javascript
fetch('/routes/admin/manageServer.php', {
    headers: {
        'Authorization': 'Bearer ' + adminToken
    }
})
```

**Restart Server:**
```javascript
fetch('/routes/admin/manageServer.php?action=restart', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + adminToken
    }
})
```

### Sample Admin UI Component (React)

```jsx
import React, { useState, useEffect } from 'react';

function WebSocketControl() {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(false);
    
    const checkStatus = async () => {
        const res = await fetch('/routes/admin/websocketControl.php?action=status', {
            headers: { 'Authorization': 'Bearer ' + localStorage.getItem('token') }
        });
        const data = await res.json();
        setStatus(data.data);
    };
    
    const controlServer = async (action) => {
        setLoading(true);
        await fetch(`/routes/admin/websocketControl.php?action=${action}`, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + localStorage.getItem('token') }
        });
        await checkStatus();
        setLoading(false);
    };
    
    useEffect(() => {
        checkStatus();
        const interval = setInterval(checkStatus, 10000); // Check every 10s
        return () => clearInterval(interval);
    }, []);
    
    return (
        <div className="websocket-control">
            <h3>WebSocket Server</h3>
            <p>Status: <span className={status?.status === 'running' ? 'text-success' : 'text-danger'}>
                {status?.status === 'running' ? '🟢 Running' : '🔴 Stopped'}
            </span></p>
            {status?.pid && <p>PID: {status.pid}</p>}
            
            <div className="button-group">
                {status?.status !== 'running' ? (
                    <button onClick={() => controlServer('start')} disabled={loading}>
                        Start Server
                    </button>
                ) : (
                    <>
                        <button onClick={() => controlServer('stop')} disabled={loading}>
                            Stop Server
                        </button>
                        <button onClick={() => controlServer('restart')} disabled={loading}>
                            Restart Server
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
```

---

## Method 2: cPanel Cron Job (Auto-Start) 🔄

### Setup Instructions

1. **Login to cPanel**
2. **Navigate to Cron Jobs** (under Advanced section)
3. **Add New Cron Job:**

```
Minute: */5 (every 5 minutes)
Hour: *
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/tradity-backend/websocket/auto_start.php
```

**Replace `/home/username/public_html/` with your actual path**

### How It Works
- Runs every 5 minutes
- Checks if WebSocket server is running
- Automatically starts it if stopped
- Server stays running 24/7

### Finding Your PHP Path
Not sure where PHP is? Add this temporary cron:
```
Command: which php > /home/username/php_path.txt
```
Then check the file to see the path.

---

## Method 3: Direct URL Trigger 🔗

### Setup
1. Add to your `.env` file:
```env
WEBSOCKET_CONTROL_KEY=your-super-secret-key-here-change-this
```

2. Keep this URL secret (only for you/admin)

### Usage

**Start Server:**
```
https://yourdomain.com/websocket/start_server.php?action=start&key=your-super-secret-key-here-change-this
```

**Stop Server:**
```
https://yourdomain.com/websocket/start_server.php?action=stop&key=your-super-secret-key-here-change-this
```

**Check Status:**
```
https://yourdomain.com/websocket/start_server.php?action=status&key=your-super-secret-key-here-change-this
```

**Restart Server:**
```
https://yourdomain.com/websocket/start_server.php?action=restart&key=your-super-secret-key-here-change-this
```

### Security Warning ⚠️
- Keep the URL private
- Use a strong random key
- Consider IP whitelisting in `.htaccess`

---

## Method 4: One-Time Manual Start (Temporary)

If your host allows SSH access:

```bash
# Login via SSH
ssh username@yourdomain.com

# Navigate to project
cd public_html/tradity-backend

# Start server
php websocket/start_server.php start

# Check status
php websocket/start_server.php status
```

---

## Deployment Checklist for Buyers

### Step 1: Upload Files
- ✅ Upload entire project including `vendor/` folder
- ✅ Set folder permissions: `755` for directories, `644` for files
- ✅ Ensure `websocket/` folder is writable (for PID and log files)

### Step 2: Configure Environment
```env
# Add to .env
WEBSOCKET_CONTROL_KEY=generate-a-random-key-here
```

### Step 3: Choose Startup Method

**Option A - Admin Dashboard (Best for non-technical users):**
1. Login as admin
2. Go to Settings > WebSocket Server
3. Click "Start Server"
4. Done! ✅

**Option B - Cron Job (Best for reliability):**
1. Login to cPanel
2. Go to Cron Jobs
3. Add: `*/5 * * * * /usr/bin/php /path/to/websocket/auto_start.php`
4. Server auto-starts and stays running ✅

**Option C - Manual URL (Quick start):**
1. Visit: `yourdomain.com/websocket/start_server.php?action=start&key=YOUR_KEY`
2. Bookmark for future use ✅

---

## Troubleshooting

### Server Won't Start
**Check PHP Path:**
```bash
which php
# or
whereis php
```

**Check Permissions:**
```bash
chmod 755 websocket/
chmod 644 websocket/*.php
```

**Check Logs:**
```bash
tail -f websocket/server.log
```

### Server Stops Randomly
**Solution:** Use Method 2 (Cron Job) for auto-restart

### Can't Connect from Frontend
**Check Port:** cPanel usually blocks custom ports. You may need:
1. Contact host to open port 8080
2. Or use CloudFlare tunnel
3. Or use reverse proxy

---

## Production Best Practices

### 1. Use Process Monitor
Add cron job to restart if crashed:
```
*/5 * * * * /usr/bin/php /path/to/websocket/auto_start.php
```

### 2. Log Rotation
Add to cron (weekly):
```
0 0 * * 0 mv /path/to/websocket/server.log /path/to/websocket/server.log.old
```

### 3. Monitoring
Check status endpoint periodically from frontend:
```javascript
setInterval(async () => {
    const res = await fetch('/routes/admin/websocketControl.php?action=status');
    const data = await res.json();
    if (data.data.status !== 'running') {
        // Alert admin or auto-restart
        await fetch('/routes/admin/websocketControl.php?action=start', {method: 'POST'});
    }
}, 60000); // Every minute
```

---

## Summary

| Method | Ease | Reliability | Auto-Restart | Best For |
|--------|------|-------------|--------------|----------|
| Admin Dashboard | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ❌ | Non-technical users |
| Cron Job | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅ | Production |
| URL Trigger | ⭐⭐⭐⭐ | ⭐⭐⭐ | ❌ | Quick testing |
| SSH Manual | ⭐⭐ | ⭐⭐ | ❌ | Development |

**Recommended:** Combine Admin Dashboard (for user control) + Cron Job (for auto-restart)

---

## cPanel Compatibility Details

### ✅ What Works on cPanel

1. **Background Process Creation** 
   - Uses `proc_open` (available on 95%+ of cPanel hosts)
   - Falls back to `shell_exec` if needed
   - Multiple methods ensure maximum compatibility

2. **Graceful Shutdown**
   - Uses signal file instead of `kill` commands
   - Server checks for `server.stop` file every 2 seconds
   - Works even when system commands are disabled

3. **Process Management**
   - PID file tracking
   - Automatic status detection
   - Clean shutdown handling

4. **Web-Based Control**
   - No SSH required
   - Fully manageable via HTTP requests
   - Perfect for cPanel-only access

### ⚠️ Common cPanel Limitations

1. **Custom Ports May Be Blocked**
   - Shared hosting often blocks ports 8080, 8081, etc.
   - **Solutions:**
     - Use Cloudflare WebSocket proxy
     - Upgrade to VPS
     - Use simplified architecture (frontend connects directly to Binance)

2. **Process Limits**
   - Some hosts limit background processes
   - **Solution:** Cron job ensures it restarts automatically

3. **Execution Time**
   - PHP max_execution_time may be limited
   - **Solution:** Server runs as background process (not affected)

### 🔧 Required PHP Functions (Usually Enabled)

```php
✅ proc_open        // Primary method - works on most cPanel
✅ shell_exec       // Fallback method
✅ file_get_contents // For signal files
✅ file_put_contents // For PID tracking
```

Optional (we have workarounds if disabled):
```php
⚠️ exec            // Nice to have, not required
⚠️ posix_kill      // Nice to have, not required
```

### 🚀 Deployment Checklist for Buyers

- [ ] Upload entire project with `vendor/` folder
- [ ] Configure `config/keys.php` with database credentials
- [ ] Set up cron job (recommended)
- [ ] Test server start via API endpoint
- [ ] Check `websocket/server.log` for status
- [ ] If WebSocket port blocked, use Cloudflare or simplified approach

---

## Quick Start Example

1. **Upload project with vendor folder**
2. **Add cron job:**
   ```
   */5 * * * * /usr/bin/php /home/user/public_html/tradity-backend/websocket/start_server.php start
   ```
3. **Add button to admin dashboard:**
   ```html
   <button onclick="fetch('/routes/admin/websocketControl.php?action=start', {method:'POST'})">
       Start WebSocket
   </button>
   ```
4. **Done!** Server runs 24/7 automatically ✅
