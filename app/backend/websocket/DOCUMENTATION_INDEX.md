# WebSocket Server Documentation Index

Welcome to the Tradity WebSocket Server documentation! This folder contains everything you need to set up and manage your WebSocket server.

## 🚀 Quick Start

**New user? Start here:**

1. **No SSH access?** → Use [setup.php](setup.php) (open in browser)
2. **Have SSH access?** → Read [QUICK_SETUP.md](QUICK_SETUP.md)
3. **Not sure which?** → Check [SETUP_COMPARISON.md](SETUP_COMPARISON.md)

---

## 📚 Documentation Files

### Setup Guides

| File | Purpose | Who It's For |
|------|---------|--------------|
| **[QUICK_SETUP.md](QUICK_SETUP.md)** | 5-minute setup guide | Everyone (start here!) |
| **[COMPLETE_SETUP_GUIDE.md](COMPLETE_SETUP_GUIDE.md)** | Detailed technical guide | Advanced users, troubleshooting |
| **[SETUP_COMPARISON.md](SETUP_COMPARISON.md)** | Compare setup methods | Choosing best approach |

### Security

| File | Purpose | When to Read |
|------|---------|--------------|
| **[SECURITY.md](SECURITY.md)** | Security best practices | After setup, before going live |

### Technical Reference

| File | Purpose | When to Read |
|------|---------|--------------|
| **[README.md](README.md)** | WebSocket functionality guide | Understanding how WebSocket works |
| **[CPANEL_SERVER_SETUP.md](CPANEL_SERVER_SETUP.md)** | Original cPanel guide | Reference (superseded by QUICK_SETUP.md) |

---

## 🛠️ Executable Files

### Setup & Management

| File | Purpose | How to Use |
|------|---------|------------|
| **setup.php** | Web-based setup wizard | Open in browser, then **DELETE** |
| **start_websocket.sh** | Start server script (Linux) | Run via cron or manually |
| **stop_websocket.sh** | Stop server script (Linux) | Run manually when needed |
| **start_websocket.bat** | Start server script (Windows) | Double-click or Task Scheduler |

### Cron Helper (Optional)

| File | Purpose | When Needed |
|------|---------|-------------|
| **cron_start.php** | HTTP cron endpoint | Only if shell cron doesn't work |

---

## 📝 Server Files

| File | Purpose | Notes |
|------|---------|-------|
| **server.php** | Main WebSocket server | Don't edit unless you know what you're doing |
| **server.log** | Server logs | Check for errors, can be deleted to save space |
| **server.pid** | Process ID file | Auto-created, shows if server is running |
| **server.stop** | Stop signal file | Auto-created when stopping server |

---

## 🎯 Quick Reference by Scenario

### "I just purchased the software and want to set it up"
1. Read [QUICK_SETUP.md](QUICK_SETUP.md) → Option A (No SSH)
2. Open [setup.php](setup.php) in your browser
3. Follow the wizard (3 clicks + copy/paste cron command)
4. **Delete setup.php** ⚠️
5. Done! ✅

### "I have SSH access and prefer command line"
1. Read [QUICK_SETUP.md](QUICK_SETUP.md) → Option B (SSH)
2. Run the provided commands
3. Setup cron job
4. Done! ✅

### "The server won't start"
1. Check [COMPLETE_SETUP_GUIDE.md](COMPLETE_SETUP_GUIDE.md) → Troubleshooting section
2. Review server.log for errors
3. Verify cron job is running
4. Verify scripts are executable (`ls -la *.sh` should show `rwxr-xr-x`)
5. Contact support with log files

### "I want to understand the security implications"
1. Read [SECURITY.md](SECURITY.md)
2. **Delete setup.php after successful setup** ⚠️
3. Review file permissions
4. Secure cron_start.php if using HTTP cron
5. Implement post-setup checklist

### "I'm not sure which setup method to use"
1. Read [SETUP_COMPARISON.md](SETUP_COMPARISON.md)
2. For most users: **Web setup** (no SSH required) ⭐ Recommended
3. For developers: Either method works
4. For restricted hosting: HTTP cron method

### "I want to understand how WebSocket works"
1. Read [README.md](README.md) - Technical implementation
2. Review server.php code
3. Check message types and protocol
4. Frontend integration examples

---

## 🔍 File Purpose Summary

### Must Read Before Setup
- **QUICK_SETUP.md** - Your starting point (5 minutes)
- **SECURITY.md** - Post-setup security (5 minutes)

### Reference Documentation
- **COMPLETE_SETUP_GUIDE.md** - Deep dive + troubleshooting (20 minutes)
- **SETUP_COMPARISON.md** - Choosing setup method (5 minutes)
- **README.md** - WebSocket functionality guide (15 minutes)

### Tools (Don't Edit)
- **setup.php** - Web-based setup wizard
- **start_websocket.sh** - Server startup
- **stop_websocket.sh** - Server shutdown
- **start_websocket.bat** - Windows startup

---

## 📞 Support

### Before contacting support:

1. ✅ Read QUICK_SETUP.md
2. ✅ Try the web setup (setup.php)
3. ✅ Check server.log for errors
4. ✅ Verify cron job is configured
5. ✅ Review COMPLETE_SETUP_GUIDE.md troubleshooting section

### When contacting support, provide:

- **Hosting provider** (cPanel/Plesk/VPS/etc.)
- **PHP version** (shown in setup.php or run `php -v`)
- **Contents of server.log** (last 50 lines)
- **Screenshots** of any error messages
- **What you've already tried** (checklist above)
- **Cron job configuration** (if applicable)

---

## 📊 Setup Success Checklist

After setup, verify these:

- [ ] server.pid file exists
- [ ] Dashboard shows "Server Running" (green status)
- [ ] server.log shows recent activity (timestamps within 5 minutes)
- [ ] Cron job is active in cPanel (check Cron Jobs section)
- [ ] **setup.php has been deleted** ⚠️ **CRITICAL**
- [ ] File permissions are correct (755 for .sh files)
- [ ] Can connect from frontend (WebSocket connection established)
- [ ] Prices updating in real-time

If all checked ✅ - **You're good to go!** 🎉

---

## 🔄 Update Notes

**Latest Version:** 1.0.0

When updating the software:
- Scripts may be replaced by update
- Re-run setup.php if permissions reset
- Verify cron job still points to correct absolute path
- Check server.log after update
- Test WebSocket connection from frontend

---

## 🌟 Best Practices

1. **Always use cron for production** - Don't rely on dashboard start button (it's for monitoring only)
2. **Delete setup.php after setup** - Security first! It exposes system information
3. **Monitor server.log regularly** - Catch issues early, rotate logs if they get large
4. **Keep backups of working configuration** - Easy recovery if something breaks
5. **Test updates in staging first** - Avoid production downtime

### Production Checklist:
- [ ] Server running via cron (not manual start)
- [ ] setup.php deleted
- [ ] SSL/TLS configured for WebSocket (wss://)
- [ ] Firewall allows port 8080
- [ ] Logs monitored daily
- [ ] Backups configured

---

## 📖 Reading Order Recommendations

### For End Users (No Technical Background):
1. QUICK_SETUP.md → Option A
2. Open setup.php in browser
3. Follow wizard
4. SECURITY.md (post-setup)
5. README.md (understanding features)

### For Developers (Setting Up Locally):
1. QUICK_SETUP.md → Option B
2. README.md (technical details)
3. COMPLETE_SETUP_GUIDE.md (if issues)

### For System Administrators (cPanel Hosting):
1. SETUP_COMPARISON.md (choose method)
2. QUICK_SETUP.md → Option A
3. SECURITY.md (hardening)
4. COMPLETE_SETUP_GUIDE.md (reference)

### For Software Resellers/Distributors:
1. SETUP_COMPARISON.md (understand all methods)
2. COMPLETE_SETUP_GUIDE.md (full knowledge)
3. README.md (explain to customers)
4. SECURITY.md (customer guidance)

---

## 🎓 Additional Resources

### Understanding WebSocket Technology:
- Read [README.md](README.md) - Message types, protocol, architecture
- Review server.php code comments
- Test with frontend examples provided

### Troubleshooting:
- [COMPLETE_SETUP_GUIDE.md](COMPLETE_SETUP_GUIDE.md) → Troubleshooting section
- Check server.log for error messages
- Verify PHP version (7.4+ required)
- Test cron job manually

### Security Hardening:
- [SECURITY.md](SECURITY.md) → Complete checklist
- Delete setup.php immediately after use
- Use .htaccess to restrict access
- Regular security audits

---

## 🚨 Critical Warnings

### 1. Delete setup.php After Use! ⚠️
**Why?** It exposes:
- PHP CLI path
- Server status
- Log contents
- System information

**When?** Immediately after successful cron setup
**How?** Via FTP/cPanel File Manager or: `rm setup.php`

### 2. Don't Rely on Dashboard Start Button ⚠️
**Why?** Web servers can't spawn persistent processes reliably
**Solution?** Use cron job (runs every 5 minutes, auto-restarts)
**Dashboard?** Use for monitoring only (status check always works)

### 3. Use Absolute Paths in Cron ⚠️
**Wrong:** `*/5 * * * * start_websocket.sh`
**Right:** `*/5 * * * * /bin/bash /home/user/domain/websocket/start_websocket.sh`

### 4. Test Before Going Live ⚠️
- Test in staging environment first
- Verify WebSocket connections work
- Check under load (multiple users)
- Monitor server.log for errors

---

**Ready to start?** Open [QUICK_SETUP.md](QUICK_SETUP.md) or [setup.php](setup.php) in your browser! 🚀

---

*Last Updated: January 2025*
*Version: 1.0.0*
*Questions? Check COMPLETE_SETUP_GUIDE.md or contact support*
