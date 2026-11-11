# ZKTeco → Time Card

Two connection modes (same Time Card writer as Hikvision):

| Device | Mode | How punches arrive |
|--------|------|--------------------|
| **SenseFP M2** (Live U) | `liveu` | HR pulls `GET /attendances` from LiveU cloud |
| **K40 Pro** | `adms` | Device pushes ATTLOG to `/iclock/cdata` |

## SenseFP M2 — LiveU Attendance API

Reference from LiveU Attendance API v1.0:

- **Base URL:** `https://attapi.liveucloud.com/api/v1`
- **Auth header:** `x-api-key: <Branch API key>`
- **Attendance:** `GET /attendances?from=YYYY-MM-DD&to=YYYY-MM-DD&access=Granted`

### Reference device (from Live U)

| Field | Value |
|-------|--------|
| Serial Number | `FQQ2254700019` |
| Name | Test Beats Tech 2 |
| Branch API key | *(paste in Time Card — store encrypted; do not commit)* |

### Steps

1. Time Card → **ZKTeco / LiveU Fingerprint Sync** → **Add Device**
2. Connection = **LiveU cloud — SenseFP M2**
3. Name = `Test Beats Tech 2`, SN = `FQQ2254700019`, paste Branch API key
4. **Test** — must return Connected (401 means Live U has not activated that key yet)
5. **Sync Now** — pick date range; punches become Time Card rows
6. Scheduler: `php artisan liveu:sync-attendance` every `LIVEU_POLL_INTERVAL` minutes (default 5)

**LiveU `memberId` = HR Attendance Employee No**

### Env (optional)

```env
LIVEU_API_BASE_URL=https://attapi.liveucloud.com/api/v1
LIVEU_POLL_INTERVAL=5
```

## K40 Pro — ADMS push (same LAN)

The K40 Pro **pushes** punches to HR. HR does **not** read the device memory when you click Recent punches.

1. Register device in Time Card (Connection = ADMS), enter SN + IP `192.168.1.25`.
2. On this PC, `APP_URL` / `ZKTECO_LAN_HOST` must be the **LAN IP** (e.g. `192.168.1.147`), not `127.0.0.1`.
3. Run API so the device can reach it: `php artisan serve --host=0.0.0.0 --port=8000`
4. Copy **ADMS URL** from the device card, e.g. `http://192.168.1.147:8000/iclock`
5. On K40 Pro: **Comm. → Cloud Server / ADMS** → paste that URL → save → reboot
6. When **Last handshake** updates, scan a finger — Time Card gets a row

```env
APP_URL=http://192.168.1.147:8000
ZKTECO_LAN_HOST=192.168.1.147
```

```bash
php artisan migrate   # or tables self-create on first API use
```
