# BlastIQ Production Deploy Checklist

This is the consolidated checklist for deploying BlastIQ to production. It captures real-world findings from the Phase 17/18/19a deployments and is meant to be the durable reference — if something here is out of date, fix this doc as part of your next change.

The standard deploy script `bash deploy.sh` handles 90% of the work. This doc covers the parts that need human judgment OR that can't be scripted (configuring third-party services, editing nginx, granting permissions to specific users).

## Quick command summary (for repeat deploys)

```bash
ssh oosadiaye@server1.tryquot.com
cd /home/oosadiaye/Blast_dplux
git pull origin main
bash deploy.sh
```

That's it for normal deploys. The validation built into `deploy.sh` (steps [10.5/11] and [11/11]) will warn you about anything broken.

The rest of this doc is for **first-time deploys** and **diagnosing warnings** that the deploy script surfaces.

---

## First-time deploy (fresh server)

If this is the first time deploying BlastIQ on a server, work through this list in order. Each section assumes the previous is complete.

### 1. System dependencies

```bash
# PHP 8.2+ with required extensions
php --version  # confirm 8.2+
# Required extensions: pdo_mysql, mbstring, openssl, xml, ctype, json, bcmath, fileinfo, tokenizer

# Composer
composer --version

# Node 20+ for asset building
node --version
npm --version

# Supervisor for queue worker + Reverb daemon
supervisorctl --version
sudo systemctl status supervisord  # or supervisor on Debian/Ubuntu
```

### 2. Repository ownership

If running deploy commands as `root` in a repo owned by another user (e.g., `oosadiaye`), git refuses to operate due to "dubious ownership." Fix once:

```bash
sudo git config --global --add safe.directory /home/oosadiaye/Blast_dplux
```

Or, cleaner — switch to the owner user before running git commands:

```bash
su - oosadiaye
cd /home/oosadiaye/Blast_dplux
```

### 3. .env file

Copy `.env.example` to `.env` if not present:

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env` and set production values:

| Key | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://blast.dpluxtech.com` (or your domain) |
| `LOG_LEVEL` | `error` (or `warning` if more verbosity needed) |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | per your MySQL setup |
| `BROADCAST_CONNECTION` | **`reverb`** (NOT `log` — see Phase 17 section below) |
| `REVERB_APP_ID` | `blastiq` |
| `REVERB_APP_KEY` | random 32-char hex (see generation below) |
| `REVERB_APP_SECRET` | random 32-char hex (different value) |
| `REVERB_HOST` | `127.0.0.1` |
| `REVERB_PORT` | `8080` |
| `REVERB_SCHEME` | `http` (internal — between nginx and Reverb) |
| `VITE_REVERB_APP_KEY` | Same as `REVERB_APP_KEY` (uses interpolation) |
| `VITE_REVERB_HOST` | `blast.dpluxtech.com` (your public domain, no protocol) |
| `VITE_REVERB_PORT` | `443` |
| `VITE_REVERB_SCHEME` | `https` |

Generate Reverb secrets:

```bash
# Run TWICE — once for REVERB_APP_KEY, once for REVERB_APP_SECRET
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
```

### 4. Database setup

```bash
# Create the MySQL database matching DB_DATABASE in .env
mysql -u root -p -e "CREATE DATABASE oosadiaye_blastiq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "GRANT ALL ON oosadiaye_blastiq.* TO 'oosadiaye_blastiq'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';"

# Run migrations + seed roles + create admin user
php artisan migrate --force
php artisan db:seed --force
```

The seeder creates `admin@blastiq.com` with password `password`. **Log in immediately and change it.**

### 5. Storage permissions

The `php-fpm` pool (or `nginx` user, depending on your setup) needs write access to `storage/` and `bootstrap/cache/`. The `deploy.sh` script tries to detect this automatically using the existing ownership of `storage/`. If you're starting fresh:

```bash
# Identify your php-fpm user (varies by hosting setup)
ps aux | grep php-fpm | head

# Set ownership (replace 'oosadiaye' with the actual user)
sudo chown -R oosadiaye:oosadiaye storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 6. Worker supervisor (Phase 14.x — campaign queue)

```bash
sudo bash deploy/install-supervisor.sh
sudo supervisorctl status blastiq-worker
# Expected: blastiq-worker:00   RUNNING
```

### 7. Reverb daemon (Phase 17 — real-time call signaling)

**This is the step most commonly missed on first deploy.** Without it, Phase 17/18 in-flight call banners don't appear (broadcasts go to log file instead).

```bash
sudo bash deploy/install-reverb.sh
sudo supervisorctl status blastiq-reverb
# Expected: blastiq-reverb   RUNNING
```

If `install-reverb.sh` fails: confirm supervisor is installed and running, confirm `/etc/supervisord.d/` (RHEL/Alma) or `/etc/supervisor/conf.d/` (Debian) directory exists.

### 8. nginx WebSocket proxy (Phase 17)

The Reverb daemon listens on `127.0.0.1:8080` only. Public traffic enters at `wss://blast.dpluxtech.com/app/` and nginx proxies to the daemon. Without this, browsers can't connect to Reverb.

Add this `location` block inside your existing nginx `server { ... }` block:

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

A reference template lives at `deploy/nginx.conf` in this repo. Then:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Verify in browser dev console (F12 → Console tab):
- Open https://blast.dpluxtech.com after login
- Look for `Pusher : State changed : connecting -> connected` log line
- If you see `WebSocket connection failed` — nginx proxy or Reverb daemon issue

### 9. Africa's Talking voice provider (Phase 18)

AT credentials are stored in the **`settings` table** (encrypted), not `.env`. Configure via the web UI:

1. Visit https://blast.dpluxtech.com/settings
2. Find the "Voice Provider (Africa's Talking)" panel
3. Enter:
   - **Username** — your AT account username
   - **API Key** — your AT API key (will be encrypted at rest via `Crypt::encryptString`)
   - **Virtual Number** — the E.164-formatted number you registered with AT (e.g., `+2348100000000`)
   - **Rate per Minute (kobo)** — default 600 (₦6/min); adjust to your AT rate card
4. Click Save

Verify via tinker:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute='echo "user: " . App\Models\Setting::get("africastalking_username") . " | virtual: " . App\Models\Setting::get("africastalking_virtual_number") . " | api: " . (App\Models\Setting::getEncrypted("africastalking_api_key") ? "SET" : "MISSING") . " | rate: " . App\Models\Setting::get("africastalking_rate_per_minute_kobo");'
```

### 10. Africa's Talking webhook URL (Phase 18 inbound)

In the AT developer dashboard:
- Voice → Phone Numbers → click your virtual number → Forwarding URL
- Set to: `https://blast.dpluxtech.com/webhooks/africastalking/voice`
- Save.

This makes inbound calls to your virtual number ring agents in BlastIQ instead of going to voicemail.

### 11. First smoke test

After all 10 steps:

1. Log in as `admin@blastiq.com` (or another agent with `conversations.call` permission)
2. Open any conversation
3. Click the green Call button
4. Click "Call now" in the modal
5. Customer's phone should ring (PSTN — no WhatsApp required)
6. Pick up; agent talks via browser mic; customer hears
7. Hang up; banner clears; row in `/calls` shows the call with cost + quality chip

If the call fails — see "Diagnosing warnings" below.

---

## Diagnosing deploy.sh warnings

The `deploy.sh` script now (as of Phase 19a+) actively validates Phase 17/18 deployment. If you see warnings:

### `BROADCAST_CONNECTION is not 'reverb'`

Your `.env` still has `BROADCAST_CONNECTION=log` (the Laravel default). Edit `.env`, change to `reverb`, save, re-run `bash deploy.sh`.

### `REVERB_APP_KEY missing or empty`

The `.env` doesn't have a `REVERB_APP_KEY=...` line OR the value is blank. Generate a secret and add it:

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
# Edit .env, add: REVERB_APP_KEY=<paste output>
# Also add REVERB_APP_SECRET if missing (generate separately):
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
```

### `blastiq-reverb supervisor program NOT installed`

The Reverb daemon was never registered. Run:

```bash
sudo bash deploy/install-reverb.sh
```

### `blastiq-reverb registered but state is 'STOPPED'` (or FATAL)

The daemon crashed or never started. Investigate:

```bash
sudo supervisorctl tail blastiq-reverb stderr
# Common causes:
#   - PHP version mismatch
#   - Port 8080 already in use (someone else's daemon)
#   - .env REVERB_PORT ≠ 8080
#   - Permission issue on storage/logs/reverb.log
```

### `nginx /app WebSocket proxy block not detected`

You haven't added the `/app` proxy block to your active nginx config. Copy the template from `deploy/nginx.conf` into the active sites-enabled config, then `sudo nginx -t && sudo systemctl reload nginx`.

### `AT username/virtual number/API key: MISSING`

Visit https://blast.dpluxtech.com/settings → "Voice Provider" panel and fill in the four fields.

---

## Permissions reference

The `RolesAndPermissionsSeeder` creates four roles. Re-running the seeder is idempotent (uses `firstOrCreate` and `syncPermissions`), so updates to role/permission config propagate to existing users on next deploy.

| Role | Sees Team page | Manages Users | Places voice calls | View all conversations |
|---|---|---|---|---|
| `super_admin` | ✅ | ✅ | ✅ | ✅ |
| `admin` | ✅ | ✅ | ✅ | ✅ |
| `manager` | ✅ (`team.view`) | ❌ | ✅ | ✅ |
| `agent` | ❌ | ❌ | ✅ (Phase 18 — granted in Phase 19a deploy update) | Only assigned chats |

If you need to grant a one-off permission to a specific user (e.g., a manager who should also be able to manage users), use tinker:

```bash
php -d opcache.enable=0 -d opcache.enable_cli=0 artisan tinker --execute='
$u = App\Models\User::where("email", "manager@example.com")->first();
$u->givePermissionTo("users.view");
echo "granted";
'
```

---

## What runs where (architecture summary)

| Component | Process | Port | Started by |
|---|---|---|---|
| HTTP requests | nginx + php-fpm | 443 (public) | systemd / cPanel |
| Queue worker | `php artisan queue:work` | (none — pulls from DB queue table) | supervisord (`blastiq-worker`) |
| Real-time WebSocket | `php artisan reverb:start` | 8080 (local), 443 (public via nginx /app) | supervisord (`blastiq-reverb`) |
| Scheduled tasks | `php artisan schedule:run` | (none) | system cron, every minute |
| Inbound WhatsApp webhook | nginx → CloudWebhookController | 443 → app | (Meta sends events) |
| Inbound AT webhook | nginx → AfricasTalkingWebhookController | 443 → app | (AT sends events) |

---

## What changed in each phase (so this doc stays current)

- **Phase 14.x** — campaign sending, queue worker via supervisor (Phase 14 init).
- **Phase 17** — Reverb daemon + nginx /app block + WebRTC inbound answer.
- **Phase 18** — Africa's Talking outbound + virtual number inbound. AT credentials in `settings` table.
- **Phase 19a** — Call quality telemetry. `quality_metrics` JSON column. `/calls` Quality column.

When making future changes that affect deployment, update this doc in the same commit.
