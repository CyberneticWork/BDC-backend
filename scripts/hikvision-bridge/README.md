# Hikvision on-site bridge (Sohan HR)

If HR runs in the cloud, it cannot open `192.168.x.x`. This Node script runs on an **office PC** on the same LAN as the fingerprint terminal (DS-K1T320EFWX / MFWX):

Device (LAN) → Bridge PC → `POST /api/hikvision/punches/{token}` → Time Cards

If HR itself runs on the office LAN, skip the bridge and use **Test / Sync Now** in Time Card.

## Steps
1. HR → Time Card → Hikvision panel → **Add Device** (LAN IP + web admin password).
2. Click **Agent config** → **Download office .env**.
3. On an always-on office PC:
   - Install Node.js 18+
   - Copy `sohan_hr_system_backend/scripts/hikvision-bridge` to that PC
   - Save the downloaded file in that folder as `.env` (or `hikvision-bridge.env`)
   - `npm install` then `npm start`, or double-click `start-bridge.bat`
   - Optional: `install-autostart.bat` so it starts after Windows login
4. Device user ID must equal HR **Attendance Employee No**.
5. Finger on device → wait ~1 minute → refresh Time Card.

Open the Punches URL in a browser first. You should see JSON `"HR Hikvision punches URL is live"`.

## Auth
The bridge uses **HTTP Digest** (same as Solar). Password must match the terminal **web** login at `http://DEVICE_IP`, not the Hik-Connect phone-app password.

A global `HIKVISION_WEBHOOK_SECRET` is optional. The Punches URL token is enough. If you set a secret on the HR server, put the same value in the office `.env` as `HIKVISION_SECRET`.

## `connect ETIMEDOUT 192.168.x.x:80`

The cloud URL is not the failing hop. This PC cannot open TCP to the terminal.

- Run the bridge on an office PC that can open `http://DEVICE_IP` in a browser.
- A laptop on Wi‑Fi `10.x`, a VPN, or a cloud VM cannot reach `192.168.1.21`.
- Confirm `DEVICE_IP` / `DEVICE_PORT` (use **443** if the device web UI is HTTPS).
