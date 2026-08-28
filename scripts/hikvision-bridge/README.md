# Hikvision on-site bridge (Sohan HR)

## Why
If HR runs in the cloud, it cannot open `192.168.x.x`. This small Node script runs on an office PC:

Device (LAN) → Bridge PC → HR `/api/hikvision/punches/{token}` → Time Cards

If HR itself runs on the office LAN, you can skip the bridge and use **Test / Sync Now** in the Hikvision panel.

## Steps
1. HR → Time Card → Hikvision panel → Add Device (LAN IP + admin password).
2. Click **Agent config** and copy **Punches URL**.
3. On an always-on office PC:
   - Install Node.js 18+
   - Copy `scripts/hikvision-bridge`
   - `copy .env.example .env` and fill DEVICE_* + CLOUD_PUNCHES_URL
   - `npm start` or double-click `start-bridge.bat`
4. Device user ID must equal HR **Attendance Employee No**.
5. Finger on device → wait ~1 minute → refresh Time Card.

## Auth
Bridge uses **HTTP Digest** (same as Solar). Password must match the terminal web admin password.
