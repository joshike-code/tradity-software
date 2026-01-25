# tradity-software
**Traditi** Professional broker php software for trading financial markets. Designed to operate similarly to popular brokers like Octa Fx.
# 💼 Traditi Software

This software was built by [Joshike-code](https://github.com/joshike-code) for **Degiant Software**.

Official website: https://degiantstore.live
Live demo: https://tradity.degiantstore.live

---

## 🚀 Features

- ✅ PHP API backend (connect via `/app/backend/api/`)
- ✅ Websocket server for live trade (Rachet/React PHP)
- ✅ ES6 JavaScript frontend (bundled with Webpack, transpiled with Babel)
- ✅ JWT-based secure authentication
- ✅ MySQL database
- ✅ Modular admin system: Superadmin + multiple Admins with specific permissions
- ✅ SPA (Single Page Application) experience
- ✅ Installable PWA (Progressive Web App)
- ✅ Service worker for better frontend caching
- ✅ Built-in error logging

---

## 🛠️ Installation Guide

> **Important:** Requires **PHP 8.2**

### Step-by-step Setup:

1. **Create a MySQL database**  
   Note down your:  
   - DB name  
   - DB user  
   - DB password  

2. **Visit your app’s domain in the browser with '/app/'**  
   Example: `https://yourdomain.com/app/`  
   You should see the **Login Page**.

3. **Enter any login credentials**  
   This takes you to the **Installation Wizard**.

4. **Fill in your configuration details**  
   Including accurate DB credentials.

5. **Troubleshooting Tips:**
   - If you see **"Server Error"**, click **Try Again**.
   - If the error persists, check your **PHP version** (must be 8.2).
   - If the **Install button** is not clickable, try highlighting the support email field.

6. **Default Superadmin Credentials:**
   - After installation, you'd need to login to superadmin. Use the below default credentials
   - Email: owner@traditi.com
   - Password: 1234

7. **Set your Degiant Passkey**  
You will be prompted to input a **License key** after login.  
Obtain this from your merchant after purchasing the software.

8. **Configure Mail Settings**  
Go to **Admin Settings** and configure mail settings to enable OTP verification for new users.

9. **Set Up Cron**  
Add the 2 scripts below to your server’s cron scheduler (every 5 minutes):
   - app/backend/cron/cron_update.php (For email queue and other functional system iterations)
   - app/backend/websocket/start_websocket.sh (To keep the websocket server alive on cpanel)
> Get the full and correct path as well as instructions for your cron setup by visiting `https://yourdomain.com/app/backend/websocket/get_cron_commands.php`

10. **Configure API Keys**  
Configure your API keys in **Admin Settings** for realtime price data and chart history. Get them at:
   - Finnhub: `finnhub.io`
   - Twelve Data: `twelvedata.com`
> **Important:** Do not use the same API keys on multiple Traditi softwares. One key per Traditi software

11. **Use European Servers!**  
The software also uses binance API which might fail if you do not use a shared hosting/VPS server located in Europe.

12. **Done!**  
 You can now use your Traditi platform. Be sure to update it when prompted.

---

## ⚙️ Manual Installation (If Wizard Fails)

If the Installation Wizard doesn't work:

- Create a `.env` file inside `/app/backend`
- Use `.env-example` as a reference
- You can always update settings via this file

---

## 📂 Error Logs

- Server Errors → `app/backend/error/server_errors.log`
- Client Errors → `app/backend/error/client_errors.log`

These logs can help you diagnose any issues in the app.

---

## 🌐 API Overview

Your backend API base URL:
https://yourdomain.com/app/backend/api


Supports methods: `GET`, `POST`, `PUT`, `DELETE`

---

## 📬 Support

For assistance, open an [Issue](https://github.com/joshike-code/tradity-software/issues)  

---

## 🔒 Licensing

This software is commercial and licensed. Redistribution without permission is not allowed.

---