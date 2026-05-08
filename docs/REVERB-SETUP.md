# Reverb Setup Guide

Complete walkthrough for installing and configuring Laravel Reverb on a production BlastIQ server. This is required for Phase 17 (inbound WhatsApp call browser answer) and Phase 18 (outbound PSTN dial via Africa's Talking) — without it, real-time call signaling between the server and agent browsers doesn't work, and the in-flight call banners never appear.

If you just want to verify what's broken on an existing deploy, run `bash deploy.sh` — its [10.5/11] step actively validates each piece of the Reverb stack and tells you what's missing.

---

## What Reverb does in BlastIQ

When a customer calls your business number, the server learns about it via webhook (Meta or Africa's Talking). The agent's browser, however, has no idea — until Reverb pushes a real-time event to the agent's private channel. The agent's dashboard subscribes via Laravel Echo, receives the SDP offer + caller info, and renders the Accept/Decline banner.

Without Reverb (or with `BROADCAST_CONNECTION=log`), every `CallRinging` / `CallTerminated` / `CallClaimed` broadcast event is silently swallowed into the log file. Symptoms on production:

- Agent never sees the incoming-call banner.
- Outbound calls show "Calling..." indefinitely (no Connected transition).
- `claim` race protection between multiple browser tabs doesn't work.
- Stale-call cleanup broadcasts go nowhere.

This is the most-commonly-missed part of a first-time Phase 17+ deployment.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│ Laravel app (php-fpm)                                   │
│   broadcast(new CallRinging($call))                     │
│         ↓                                               │
│   BROADCAST_CONNECTION=reverb (.env)                    │
│         ↓                                               │
│   Pusher-protocol HTTP request to local Reverb          │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│ Reverb daemon — long-running PHP process                │
│   php artisan reverb:start                              │
│   Listens on 127.0.0.1:8080 (local-only)                │
│   Managed by supervisord as 'blastiq-reverb'            │
└─────────────────────────────────────────────────────────┘
                         ▲
                         │ wss:// upgrade
┌─────────────────────────────────────────────────────────┐
│ nginx                                                   │
│   location /app { proxy_pass http://127.0.0.1:8080; }   │
│   Public WSS at https://blast.dpluxtech.com/app         │
└─────────────────────────────────────────────────────────┘
                         ▲
                         │
┌─────────────────────────────────────────────────────────┐
│ Agent's browser                                         │
│   Laravel Echo client (resources/js/app.js)             │
│   window.Echo.private(`user.${userId}`)                 │
│   .listen('.call.ringing', handler)                     │
└─────────────────────────────────────────────────────────┘
```

Three things must be true for this to work end-to-end:

1. **Laravel app** knows to broadcast through Reverb (`BROADCAST_CONNECTION=reverb` in `.env`)
2. **Reverb daemon** is running on `127.0.0.1:8080` (managed by supervisord)
3. **nginx** proxies the public WebSocket path `/app` to the local daemon

If any of those is missing, broadcasts fail silently. The deploy.sh validation now warns about each.

---

## Setup walkthrough (first-time)

Time required: about 10 minutes if everything goes smoothly. Allow more if nginx config has unusual structure.

### Step 1 — Generate Reverb secrets

Run this command twice on the production server. Each output is one secret. Keep both for the next step.

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
# Output 1: copy as REVERB_APP_KEY
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
# Output 2: copy as REVERB_APP_SECRET
```

These are your application's auth tokens between Laravel ↔ Reverb. Both must be 32 hex chars (16 random bytes).

### Step 2 — Update `.env`

```bash
nano /home/oosadiaye/Blast_dplux/.env
```

**Find** this line (Laravel default):

```env
BROADCAST_CONNECTION=log
```

**Change to**:

```env
BROADCAST_CONNECTION=reverb
```

**Then add this block at the end of the file** (replace the placeholder values with what you generated in Step 1):

```env
# Reverb (Phase 17 — real-time call signaling)
REVERB_APP_ID=blastiq
REVERB_APP_KEY=PASTE_FIRST_RANDOM_HERE
REVERB_APP_SECRET=PASTE_SECOND_RANDOM_HERE
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Vite-exposed (browser reads via import.meta.env)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="blast.dpluxtech.com"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

**Important** about the `VITE_*` keys:
- `VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"` is variable interpolation — Laravel will substitute the value of `REVERB_APP_KEY` at config-load time. Keep the dollar-brace syntax.
- `VITE_REVERB_HOST` is your **public domain without protocol** (no `https://`).
- `VITE_REVERB_PORT=443` — public WSS port (the browser connects via 443; nginx proxies internally to 8080).
- `VITE_REVERB_SCHEME=https` — public scheme. The browser uses `wss://` (secure WebSocket) over 443.

Save: `Ctrl+O`, `Enter`, `Ctrl+X` in nano.

### Step 3 — Verify the .env block parses cleanly

```bash
cd /home/oosadiaye/Blast_dplux
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan config:clear

# Confirm the keys read back correctly:
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute='
echo "BROADCAST: " . config("broadcasting.default") . PHP_EOL;
echo "APP_KEY: " . config("broadcasting.connections.reverb.key") . PHP_EOL;
echo "HOST: " . config("broadcasting.connections.reverb.options.host") . PHP_EOL;
echo "PORT: " . config("broadcasting.connections.reverb.options.port") . PHP_EOL;
'
```

Expected output:

```
BROADCAST: reverb
APP_KEY: <your generated key>
HOST: 127.0.0.1
PORT: 8080
```

If `BROADCAST` shows `log`, the `.env` change didn't take. Re-check that you saved the file.

### Step 4 — Install the supervisor program

Reverb is a long-running daemon. supervisord keeps it alive across crashes and reboots. The repo ships an installer that handles path substitution + system-specific config locations.

```bash
sudo bash /home/oosadiaye/Blast_dplux/deploy/install-reverb.sh
```

What this does:

1. Detects whether your supervisor uses `/etc/supervisord.d/` (RHEL/Alma) or `/etc/supervisor/conf.d/` (Debian/Ubuntu)
2. Detects which user owns `storage/` (the user Reverb runs as)
3. Substitutes `__PROJECT_PATH__` and `__RUN_AS_USER__` placeholders in `deploy/supervisor-reverb.conf`
4. Writes the rendered config to `/etc/supervisord.d/blastiq-reverb.ini` (or `.conf`)
5. Runs `supervisorctl reread` + `supervisorctl update` + starts the daemon

Expected output ends with:

```
=== Done ===
Status:
blastiq-reverb        RUNNING   pid 12345, uptime 0:00:01
```

If the status shows `STOPPED`, `FATAL`, or `BACKOFF`, the daemon failed to start. Check the log:

```bash
sudo supervisorctl tail blastiq-reverb stderr
```

Common causes:

- **Port 8080 already in use** — another process owns it. Check: `sudo lsof -i :8080`. Kill it or change `REVERB_PORT` in `.env` and re-run `install-reverb.sh`.
- **PHP version mismatch** — Reverb requires PHP 8.2+. Check: `php -v`.
- **Permission on log file** — `storage/logs/reverb.log` couldn't be created. Re-run `chown` on `storage/logs/`.

### Step 5 — Add the nginx WebSocket location block

The Reverb daemon listens locally on port 8080. Public traffic enters at `wss://blast.dpluxtech.com/app/` and nginx proxies the WebSocket upgrade to the local daemon. Without this, browsers can't reach Reverb.

First, find your active nginx config for the BlastIQ domain:

```bash
sudo nginx -T 2>&1 | grep -E "server_name.*blast" -A 3 | head -10
```

This shows where your `server_name blast.dpluxtech.com;` is defined (typically `/etc/nginx/sites-enabled/blastiq.conf` or similar; on cPanel/Plesk hosts the path varies).

Edit that file:

```bash
sudo nano /etc/nginx/sites-available/blastiq.conf
```

Find the existing `server { ... }` block and **add this `location` block inside it** (before the closing brace, alongside other `location` blocks):

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
}
```

Save. Then validate + reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

`nginx -t` should print `syntax is ok` + `test is successful`. If it errors, fix the syntax (usually a missing `;` or unclosed `{`) and try again. Don't run `reload` until `nginx -t` is clean — a bad config will break ALL sites on the server.

### Step 6 — Rebuild assets (Vite picks up new VITE_REVERB_* vars)

Vite bakes the `VITE_*` env vars into the compiled JS at build time. Since we just added them to `.env`, the existing build doesn't know about them — rebuild:

```bash
cd /home/oosadiaye/Blast_dplux
npm run build
```

Expected: build completes without errors, prints something like `✓ built in 1.5s`.

If you skip this step, browsers will load the OLD JS that doesn't know your Reverb config — they'll either fail to connect to Echo or connect with wrong credentials.

### Step 7 — Final verification (browser console)

This is the step that actually confirms it works.

1. Open https://blast.dpluxtech.com in a browser. Log in.
2. Open browser dev tools → Console tab.
3. Look for log messages from Pusher (Echo uses the Pusher protocol):

**✅ Success looks like:**
```
Pusher : State changed : initialized -> connecting
Pusher : State changed : connecting -> connected
Pusher : Event sent : {"event":"pusher:subscribe","data":{...,"channel":"private-user.1"}}
Pusher : Event recd : {"event":"pusher_internal:subscription_succeeded",...}
```

**❌ Failure modes to recognize:**

| Console error | Likely cause | Fix |
|---|---|---|
| `WebSocket connection to 'wss://...' failed` | nginx /app block missing or daemon down | Re-check Step 5; `sudo supervisorctl status blastiq-reverb` |
| `Pusher : State changed : connected -> failed` | wrong APP_KEY between server and browser | VITE_REVERB_APP_KEY must equal REVERB_APP_KEY in .env; rebuild assets |
| `Auth failed: 401 Unauthorized` | private channel auth failing | Verify `routes/channels.php` exists with the `user.{id}` channel |
| `Auth failed: 419 CSRF` | session cookie not reaching the auth endpoint | Check `SESSION_DOMAIN` in `.env` matches your public domain |
| No Pusher logs at all | Echo never initialized; old build | `npm run build` (Step 6) was skipped; rebuild |

### Step 8 — End-to-end smoke test

The proof: place a real call.

1. Make sure at least one agent is logged in as a separate browser/incognito session.
2. Trigger an inbound call to your Meta WhatsApp number (or Africa's Talking virtual number).
3. The agent's browser should show the incoming-call banner within ~100ms (real-time push). If it takes the 3-second polled fallback, Echo isn't connected — re-verify Step 7.
4. Click Accept. Audio flows. Hang up. Banner clears immediately (CallTerminated broadcast).

If all that works — Reverb is set up correctly. **Don't touch `.env` or supervisor again** until you upgrade or scale.

---

## Maintenance

### Restart the daemon (after config changes)

```bash
sudo supervisorctl restart blastiq-reverb
```

### View live logs

```bash
sudo supervisorctl tail -f blastiq-reverb stdout
# or
tail -f /home/oosadiaye/Blast_dplux/storage/logs/reverb.log
```

### Update Reverb (composer dep)

When `laravel/reverb` ships a new version:

```bash
cd /home/oosadiaye/Blast_dplux
composer update laravel/reverb
sudo supervisorctl restart blastiq-reverb
```

### Rotate Reverb secrets

Every 6-12 months, rotate `REVERB_APP_KEY` and `REVERB_APP_SECRET`:

```bash
# 1. Generate new secrets (Step 1)
# 2. Edit .env with new values (Step 2)
# 3. Rebuild assets (Step 6 — VITE_REVERB_APP_KEY mirrors the server one)
# 4. Restart Reverb
sudo supervisorctl restart blastiq-reverb
# 5. Hard-refresh agent browsers (Ctrl+Shift+R) so they pick up the new key
```

Existing connected clients will be disconnected and reconnect with new auth. Active calls during rotation may drop their real-time signaling — schedule rotation during low-call hours.

---

## Common confusions

### "BROADCAST_CONNECTION=reverb but events still go to log"

You probably forgot `php artisan config:clear`. Laravel caches the config file; if you cached the OLD config (with `BROADCAST_CONNECTION=log`), changing `.env` alone has no effect. Always clear:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan config:clear
```

`bash deploy.sh` does this in step [8/11], so a normal deploy after `.env` change handles it.

### "Browser shows Pusher : connected but no events arrive"

Channel subscription, not connection. Check:
- `routes/channels.php` has `Broadcast::channel('user.{id}', ...)` defined
- The user's auth-cookie is being sent on the WebSocket subscribe handshake (`SESSION_DOMAIN` set correctly)
- The event class implements `ShouldBroadcast` (not `ShouldBroadcastNow` unless you intend synchronous dispatch)

### "Reverb works locally but not in production"

Almost always nginx — local uses `php artisan reverb:start` directly on `localhost:8080` and the browser connects there with `wss://localhost:8080`. Production goes through nginx. Verify Step 5.

### "I have multiple sites on this server — does Reverb conflict?"

Each Laravel app needs its own Reverb instance on a unique port. If site A uses 8080, site B should use 8081 — adjust `REVERB_PORT` in each `.env` and the corresponding `proxy_pass` in nginx. The supervisor program names should differ too (e.g., `blastiq-reverb` vs `siteb-reverb`).

### "Can I run Reverb without supervisor (e.g., for development)?"

Yes:

```bash
php artisan reverb:start --host=127.0.0.1 --port=8080
```

This blocks the terminal. Useful for development; not for production (no auto-restart on crash).

---

## Reference: what's in this repo

| File | Purpose |
|---|---|
| `composer.json` | Includes `laravel/reverb` dependency |
| `config/reverb.php` | Reverb config (apps, hosts, scaling). Auto-published by `php artisan reverb:install` |
| `config/broadcasting.php` | Has the `reverb` driver definition |
| `routes/channels.php` | Defines the `user.{id}` private channel auth |
| `app/Events/Calling/{CallRinging,CallClaimed,CallTerminated}.php` | The events that get broadcast |
| `resources/js/app.js` | Initializes Echo client; reads `VITE_REVERB_*` env vars |
| `resources/js/calls.js` | Phase 17 — subscribes to `user.{id}` for inbound Meta calls |
| `resources/js/outbound-call.js` | Phase 18 — subscribes for AT outbound + AT inbound |
| `deploy/supervisor-reverb.conf` | Supervisor program template |
| `deploy/install-reverb.sh` | Installer that path-substitutes + registers |
| `deploy/nginx.conf` | Reference nginx config including the `/app` block |

For the broader deploy picture, see [`PRODUCTION-DEPLOY.md`](PRODUCTION-DEPLOY.md).
