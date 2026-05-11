#!/bin/bash
set -euo pipefail

# BlastIQ deployment script
# Run from the project root directory (cd to it first, or set BLASTIQ_DIR).
#
# Idempotent — safe to re-run after a partial failure. Each step is explicit
# so you can copy/paste individual lines for troubleshooting.

cd "${BLASTIQ_DIR:-$(pwd)}"

echo "=== BlastIQ Deploy ==="
echo "Working directory: $(pwd)"
echo ""

echo "[1/11] Pulling latest code..."
git pull origin main

echo "[2/11] Removing Vite hot file (created if dev server ever ran here)..."
# Without this, Laravel's @vite() directive will keep emitting <script> tags
# pointing to a long-dead local dev server, breaking every page with CORS errors.
rm -f public/hot

echo "[3/11] Installing PHP deps..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Smoke-test that Reverb's runtime deps are present. If a previous deploy
# pulled a composer.lock referencing laravel/reverb but composer install was
# skipped (manual git pull outside this script), the app cannot boot once
# BROADCAST_CONNECTION=reverb is set in .env — every page 500s with
# "Class Pusher\Pusher not found" because routes/channels.php resolves the
# broadcaster during BootProviders, before any HTTP handling.
#
# Fail fast here with an actionable message, instead of letting the deploy
# "succeed" and then having every page return 500.
if grep -q "^BROADCAST_CONNECTION=reverb" .env 2>/dev/null; then
  if ! php -r "require 'vendor/autoload.php'; exit(class_exists('Pusher\\Pusher') ? 0 : 1);" 2>/dev/null; then
    echo "  ✗ FATAL: BROADCAST_CONNECTION=reverb but Pusher\\Pusher class missing."
    echo "    composer install above should have pulled pusher/pusher-php-server"
    echo "    (transitive dep of laravel/reverb). Try:"
    echo "      composer install --no-interaction --prefer-dist --no-dev"
    echo "    If that still fails, re-resolve the lockfile:"
    echo "      composer update laravel/reverb pusher/pusher-php-server"
    exit 1
  fi
fi

echo "[4/11] Installing JS deps + building assets..."
# `npm ci` is faster + reproducible (uses package-lock.json verbatim).
# `--omit=dev` flag only skips devDependencies; build itself works fine because
# laravel-vite-plugin and vite are in dependencies, not devDependencies.
npm ci --omit=dev || npm install --omit=dev
npm run build

echo "[5/11] Ensuring storage symlink + permissions..."
# Phase 13.0+ writes campaign-header uploads to storage/app/public/campaign-headers/
# and references them via /storage/campaign-headers/... — that URL only works if
# the public/storage symlink exists. Idempotent: artisan recreates if broken.
php artisan storage:link
mkdir -p storage/app/public/campaign-headers

# Permission fix is the trickiest part of any Laravel deploy because:
#  - PHP-FPM might run as a per-user pool (e.g. cPanel/Plesk style: user 'oosadiaye')
#  - OR as a generic web user (www-data, nginx, apache)
# Don't guess — detect it from the EXISTING file ownership inside storage/, which
# is whatever user has been writing there. Falls back to the deploy user.
STORAGE_OWNER=$(stat -c '%U' storage 2>/dev/null || stat -f '%Su' storage 2>/dev/null || echo "$(whoami)")
STORAGE_GROUP=$(stat -c '%G' storage 2>/dev/null || stat -f '%Sg' storage 2>/dev/null || echo "$(whoami)")
chown -R "$STORAGE_OWNER":"$STORAGE_GROUP" storage bootstrap/cache 2>/dev/null || true
chown -h "$STORAGE_OWNER":"$STORAGE_GROUP" public/storage 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "[6/11] Running migrations..."
php artisan migrate --force

echo "[7/11] Seeding roles + permissions (idempotent — uses firstOrCreate)..."
# After Phase 11 (spatie/laravel-permission), the roles + permissions tables
# must contain rows for the User model's HasRoles trait to work. Without this,
# ANY authenticated request 500s with "Table model_has_roles doesn't exist"
# (during initial deploy) or "No role super_admin found" (if roles table empty).
# Re-running this is safe — every Permission/Role uses firstOrCreate, and
# admin@blastiq.com is upgraded to super_admin only if not already.
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force

echo "[8/11] Clearing stale caches BEFORE re-caching..."
# Order matters: clear FIRST so re-cache picks up post-pull source, not pre-pull cached output.
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear

echo "[9/11] Re-caching for production speed..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[10/11] Restarting queue worker..."
# Three layers, most-specific first:
#
# 1. If a supervisor program 'blastiq-worker' is registered, restart it.
#    First-time setup: bootstrap with `sudo bash deploy/install-supervisor.sh`.
#
# 2. Else if Horizon is installed AND Predis is loadable, terminate Horizon.
#    The Predis check avoids the "Class Predis\Client not found" hard error
#    on hosts where Horizon was half-configured but Predis was never installed.
#
# 3. Else broadcast `queue:restart`. Note: stale workers started manually
#    long ago will NOT pick up code changes from queue:restart in some edge
#    cases (the master is supposed to gracefully exit and a wrapper restart
#    it — without supervisor, nothing restarts it). The install-supervisor.sh
#    script handles this transition cleanly.
if sudo supervisorctl status blastiq-worker:* >/dev/null 2>&1; then
  echo "  Supervisor program found — restarting blastiq-worker:*"
  sudo supervisorctl restart blastiq-worker:*
elif php artisan list 2>/dev/null | grep -q "horizon:terminate" \
     && php -r "exit(class_exists('Predis\\Client') ? 0 : 1);" 2>/dev/null; then
  echo "  Horizon (with Predis) detected — terminating master"
  php artisan horizon:terminate
  sudo supervisorctl restart blastiq-horizon 2>/dev/null || true
else
  echo "  No supervisor program / no Horizon — broadcasting queue:restart"
  echo "  TIP: Run 'sudo bash deploy/install-supervisor.sh' once to make"
  echo "       worker restarts automatic and reboot-survivable."
  php artisan queue:restart
fi

# Reverb daemon (Phase 17 — inbound call browser answer).
# Active validation: check that .env has the required keys, and that
# the supervisor program is registered + running. Each missing piece
# emits an actionable WARNING (not an error — the deploy still completes,
# but the operator knows what's broken).
echo ""
echo "[10.5/11] Reverb daemon validation..."

REVERB_FAILURES=0

if ! grep -q "^BROADCAST_CONNECTION=reverb" .env 2>/dev/null; then
  echo "  ⚠ WARNING: .env BROADCAST_CONNECTION is not 'reverb'."
  echo "    All CallRinging/CallTerminated/CallClaimed events will go to"
  echo "    the log file instead of pushing to browsers. Phase 17/18 UI"
  echo "    will be partially broken (in-flight banners won't appear)."
  echo "    Fix: edit .env, set BROADCAST_CONNECTION=reverb, add the"
  echo "    REVERB_* + VITE_REVERB_* keys (see .env.example), then re-run."
  REVERB_FAILURES=$((REVERB_FAILURES + 1))
fi

if ! grep -q "^REVERB_APP_KEY=" .env 2>/dev/null || \
   ! grep -qE "^REVERB_APP_KEY=.+" .env 2>/dev/null; then
  echo "  ⚠ WARNING: REVERB_APP_KEY missing or empty in .env."
  echo "    Generate with: php -r \"echo bin2hex(random_bytes(16));\""
  REVERB_FAILURES=$((REVERB_FAILURES + 1))
fi

# Catch unreplaced placeholder values. These strings appear in setup docs
# (docs/REVERB-SETUP.md) and the JS Echo client would receive them verbatim,
# causing every page's browser console to spam:
#   WebSocket connection to '.../app/GENERATE_RANDOM_32_CHARS_HERE' failed
# (or similar with REPLACE_ME / changeme). Better to abort the deploy
# before the broken bundle ships.
if grep -qE "^REVERB_APP_KEY=(GENERATE_RANDOM_32_CHARS_HERE|REPLACE_ME|changeme|TODO|YOUR_KEY_HERE)" .env 2>/dev/null; then
  echo "  ✗ FATAL: REVERB_APP_KEY is still the placeholder text from the setup docs."
  echo "    Production .env contains the literal example value instead of a"
  echo "    real key. Generate one and replace it:"
  echo "      php -r \"echo bin2hex(random_bytes(16));\""
  echo "    Then edit .env, set REVERB_APP_KEY=<that value> AND the matching"
  echo "    REVERB_APP_SECRET, then re-run this script."
  exit 1
fi

# Check if supervisorctl knows about blastiq-reverb
if command -v supervisorctl >/dev/null 2>&1; then
  if sudo supervisorctl status blastiq-reverb >/dev/null 2>&1; then
    REVERB_STATE=$(sudo supervisorctl status blastiq-reverb 2>/dev/null | awk '{print $2}')
    if [ "$REVERB_STATE" = "RUNNING" ]; then
      echo "  ✓ blastiq-reverb supervisor program: RUNNING"
    else
      echo "  ⚠ WARNING: blastiq-reverb registered but state is '$REVERB_STATE'."
      echo "    Try: sudo supervisorctl restart blastiq-reverb"
      REVERB_FAILURES=$((REVERB_FAILURES + 1))
    fi
  else
    echo "  ⚠ WARNING: blastiq-reverb supervisor program NOT installed."
    echo "    First-time setup: sudo bash deploy/install-reverb.sh"
    REVERB_FAILURES=$((REVERB_FAILURES + 1))
  fi
fi

# Check that nginx has the WebSocket /app proxy block (heuristic — looks
# for proxy_pass to 127.0.0.1:8080 across enabled site configs).
if [ -d /etc/nginx/sites-enabled ]; then
  if ! grep -rq "proxy_pass http://127.0.0.1:8080" /etc/nginx/sites-enabled 2>/dev/null; then
    echo "  ⚠ WARNING: nginx /app WebSocket proxy block not detected."
    echo "    Browser Echo will fail to connect to wss://\$HOST/app."
    echo "    Add the block from deploy/nginx.conf to your active config,"
    echo "    then: sudo nginx -t && sudo systemctl reload nginx"
    REVERB_FAILURES=$((REVERB_FAILURES + 1))
  fi
fi

if [ "$REVERB_FAILURES" -eq 0 ]; then
  echo "  All Reverb checks passed."
fi

echo ""
echo "[11/11] Final verification..."
echo "  - Manifest exists?           $([ -f public/build/manifest.json ] && echo YES || echo NO)"
echo "  - public/hot gone?           $([ ! -f public/hot ] && echo YES || echo NO)"
ROLES=$(php artisan tinker --execute="echo Spatie\\Permission\\Models\\Role::count();" 2>/dev/null | tail -1)
echo "  - Roles seeded?              ${ROLES:-0} roles in DB (expect 4)"
ADMIN_ROLE=$(php artisan tinker --execute="echo App\\Models\\User::where('email','admin@blastiq.com')->first()?->roles->pluck('name')->implode(',');" 2>/dev/null | tail -1)
echo "  - admin@blastiq.com role:    ${ADMIN_ROLE:-MISSING}"

# Phase 18 — Africa's Talking voice provider config presence
AT_USER=$(php artisan tinker --execute="echo App\\Models\\Setting::get('africastalking_username') ?: 'MISSING';" 2>/dev/null | tail -1)
AT_VIRT=$(php artisan tinker --execute="echo App\\Models\\Setting::get('africastalking_virtual_number') ?: 'MISSING';" 2>/dev/null | tail -1)
AT_KEY=$(php artisan tinker --execute="echo App\\Models\\Setting::getEncrypted('africastalking_api_key') ? 'SET' : 'MISSING';" 2>/dev/null | tail -1)
echo "  - AT username:               ${AT_USER}"
echo "  - AT virtual number:         ${AT_VIRT}"
echo "  - AT API key:                ${AT_KEY}"
if [ "$AT_USER" = "MISSING" ] || [ "$AT_VIRT" = "MISSING" ] || [ "$AT_KEY" = "MISSING" ]; then
  echo "  ⚠ Configure missing AT credentials at: https://blast.dpluxtech.com/settings"
fi

echo ""
echo "=== Deploy complete ==="
if [ "$REVERB_FAILURES" -gt 0 ]; then
  echo ""
  echo "⚠ NOTE: Reverb validation found $REVERB_FAILURES issue(s) above."
  echo "  Phase 17/18 real-time call UI will be degraded until resolved."
fi
