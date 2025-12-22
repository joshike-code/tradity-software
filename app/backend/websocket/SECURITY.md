# ⚠️ SECURITY NOTICE

## Important Files to Delete After Setup

After successfully setting up your WebSocket server, **DELETE** these files for security:

### 1. setup.php
**Location:** `websocket/setup.php`

**Why delete it?**
- Contains system information
- Can be accessed publicly
- Allows anyone to restart your server
- May expose file paths

**When to delete:**
- ✅ After cron job is configured
- ✅ After server starts successfully
- ✅ After verifying dashboard shows "Running"

**How to delete:**
```bash
# Via SSH
rm /home/username/domain/app/backend/websocket/setup.php

# Via cPanel File Manager
Navigate to websocket folder → Delete setup.php
```

---

### 2. cron_start.php (if created)
**Location:** `websocket/cron_start.php`

**If you're using shell command cron:**
- ✅ DELETE this file (not needed)

**If you're using HTTP URL cron:**
- ⚠️ KEEP this file (needed for cron)
- ✅ CHANGE the secret key to something random
- ✅ Never share the URL publicly

---

## Post-Setup Security Checklist

After setup is complete:

- [ ] setup.php deleted
- [ ] cron_start.php secret key changed (if using HTTP cron)
- [ ] Server is running (check dashboard)
- [ ] Cron job is active (check cPanel → Cron Jobs)
- [ ] No sensitive information in server.log
- [ ] .gitignore includes setup.php

---

## File Permissions

Ensure proper permissions are set:

```bash
# Scripts should be executable (755)
chmod 755 start_websocket.sh stop_websocket.sh

# PHP files should not be executable (644)
chmod 644 server.php setup.php cron_start.php

# Log and PID files should be writable (666)
chmod 666 server.log server.pid
```

---

## Access Control

### Restrict setup.php access (before deleting)

If you need to keep setup.php temporarily, add to `.htaccess`:

```apache
<Files "setup.php">
    Order Deny,Allow
    Deny from all
    Allow from YOUR.IP.ADDRESS.HERE
</Files>
```

### Restrict cron_start.php access

If using HTTP cron, add to `.htaccess`:

```apache
<Files "cron_start.php">
    Order Deny,Allow
    Deny from all
    # Allow cPanel cron (usually localhost)
    Allow from 127.0.0.1
    Allow from ::1
</Files>
```

---

## What to Keep

These files are safe and should remain:

- ✅ server.php - Main WebSocket server
- ✅ start_websocket.sh - Startup script
- ✅ stop_websocket.sh - Shutdown script
- ✅ server.log - Log file (auto-managed)
- ✅ server.pid - Process ID file (auto-managed)
- ✅ *.md - Documentation files
- ✅ cron_start.php - Only if using HTTP cron (with secret key changed!)

---

## Regular Maintenance

### Monthly:
- Check server.log size (delete if >10MB)
- Verify cron job is still active
- Check dashboard for any issues

### After Updates:
- Re-run setup.php if scripts were updated
- Verify permissions after file changes
- Test server restart

---

## Emergency Access

If you accidentally delete cron_start.php and need to recreate it:

1. Re-upload setup.php
2. Access: https://yourdomain.com/app/backend/websocket/setup.php
3. Click "Create HTTP Cron Script"
4. Change the secret key in cron_start.php
5. Delete setup.php again

---

## Support

If you have security concerns:

1. Check file permissions (see above)
2. Verify .htaccess restrictions
3. Review server.log for unauthorized access attempts
4. Contact support if you suspect a breach

**Remember:** Security through deletion - remove setup.php after successful setup! 🔒
