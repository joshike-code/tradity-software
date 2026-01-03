# WebSocket Cache Cleanup & Restart Guide

## Problem
Account data (equity, margin, free margin, P/L) not updating in real-time even though price data streams correctly.

## Root Cause
The `cache/websocket_live_prices.json` file becomes stale due to:
- Low RAM causing process slowdown
- Binance connection drops
- Write permission issues
- Server restart needed

## Solutions (Choose based on your hosting)

### Option 1: One-Click Browser Restart (Easiest)

**Step 1:** Update the secret key in `restart_and_clean.php`:
```php
define('SECRET_KEY', 'CHANGE_ME_your-unique-secret-key-here');
```

**Step 2:** Access via browser:
```
https://yourdomain.com/app/backend/websocket/restart_and_clean.php?key=YOUR_SECRET_KEY
```

Or click the **"🔄 Restart & Clean Cache"** button in setup.php

**What it does:**
1. ✅ Stops the WebSocket server gracefully
2. ✅ Deletes `cache/websocket_live_prices.json`
3. ✅ Deletes old cache files (>1 hour old)
4. ✅ Restarts the WebSocket server
5. ✅ Shows status and last 5 log entries

---

### Option 2: Automated Cron Job (Recommended for Production)

**Setup automatic cleanup every 30 minutes:**

1. Go to **cPanel → Cron Jobs**

2. Add new cron job:
   - **Interval:** Every 30 minutes (*/30 * * * *)
   - **Command:**
   ```bash
   /usr/local/bin/php /home/YOUR_USERNAME/public_html/app/backend/websocket/restart_and_clean_cron.php >> /home/YOUR_USERNAME/cleanup.log 2>&1
   ```

3. Alternative (HTTP URL method):
   ```bash
   wget -q -O- "https://yourdomain.com/app/backend/websocket/restart_and_clean.php?key=YOUR_SECRET_KEY"
   ```

**Benefits:**
- Prevents cache from going stale
- Automatically recovers from connection drops
- No manual intervention needed
- Logs output for debugging

---

### Option 3: Manual Cleanup via File Manager (No Code Execution)

If exec() is disabled on your host:

1. Go to **cPanel → File Manager**
2. Navigate to `app/backend/cache/`
3. Delete `websocket_live_prices.json`
4. Go to `websocket/`
5. Delete `server.pid`
6. Setup cron job to run `start_websocket.sh` (server will auto-restart)

---

### Option 4: SSH Access (If Available)

```bash
# Navigate to websocket directory
cd /path/to/websocket/

# Stop server
bash stop_websocket.sh

# Clear cache
rm -f ../cache/websocket_live_prices.json

# Clear old cache files
find ../cache/ -name "*.json" -mmin +60 -delete

# Start server
bash start_websocket.sh

# Monitor logs
tail -f server.log
```

---

## Troubleshooting

### Cache file timestamp is old (>10 seconds)
**Problem:** Server not writing to cache file
**Solutions:**
- Check file permissions: `chmod 775 cache/`
- Check server logs for write errors
- Increase PHP memory limit in `server.php`: `ini_set('memory_limit', '512M');`

### Server keeps dying (Low RAM)
**Problem:** OOM killer terminating process
**Solutions:**
- Upgrade VPS to 3GB+ RAM
- Reduce number of tracked trading pairs
- Optimize `updateAccounts()` to run less frequently
- Add swap space: `sudo fallocate -l 2G /swapfile`

### Binance connection drops
**Problem:** Prices stop updating
**Solutions:**
- Check logs for "Binance WebSocket closed"
- Add reconnection logic with exponential backoff
- Monitor with: `tail -f websocket/server.log | grep -i binance`

### Exec/shell_exec disabled
**Problem:** Cannot run bash scripts
**Solutions:**
- Use `restart_and_clean.php` (uses posix_kill instead)
- Use HTTP URL cron method instead of shell command
- Contact hosting support to enable exec()

---

## Monitoring

### Check if cleanup is working:

**View cache file timestamp:**
```bash
stat cache/websocket_live_prices.json
```
Should be updated within last 10 seconds.

**Check cron log:**
```bash
tail -f cleanup.log
```

**Monitor resource usage:**
```
https://yourdomain.com/app/backend/monitoring/resource_monitor.php?key=YOUR_KEY
```

---

## Prevention

**Set up automated cleanup cron job:**
- **Every 30 minutes** - Clears stale cache
- **Every 5 minutes** - Ensures server is running (existing cron)

**Monitor server health:**
- Set up uptime monitoring (UptimeRobot, Pingdom)
- Alert when WebSocket port 443 is not responding
- Track memory usage trends

**Optimize server:**
- Increase PHP memory limit
- Reduce broadcast frequency for account updates
- Cache expensive database queries
- Consider upgrading to VPS with more RAM

---

## Files Created

1. **`restart_and_clean.php`** - Browser-accessible restart script with UI
2. **`restart_and_clean_cron.php`** - Cron-friendly version (plain text output)
3. **This README** - Documentation

---

## Quick Reference

| Action | URL |
|--------|-----|
| Restart & Clean | `/websocket/restart_and_clean.php?key=YOUR_KEY` |
| View Logs | `/websocket/view_logs.php` |
| Check Vendor | `/websocket/check_vendor.php` |
| Test Binance | `/websocket/test_binance.php` |
| Resource Monitor | `/monitoring/resource_monitor.php?key=YOUR_KEY` |
| Setup Wizard | `/websocket/setup.php` |

---

**Need Help?**
Check `websocket/server.log` for detailed error messages.
