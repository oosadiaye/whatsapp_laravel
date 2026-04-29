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

echo "[1/9] Pulling latest code..."
git pull origin main

echo "[2/9] Removing Vite hot file (created if dev server ever ran here)..."
# Without this, Laravel's @vite() directive will keep emitting <script> tags
# pointing to a long-dead local dev server, breaking every page with CORS errors.
rm -f public/hot

echo "[3/9] Installing PHP deps..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "[4/9] Installing JS deps + building assets..."
# `npm ci` is faster + reproducible (uses package-lock.json verbatim).
# `--omit=dev` flag only skips devDependencies; build itself works fine because
# laravel-vite-plugin and vite are in dependencies, not devDependencies.
npm ci --omit=dev || npm install --omit=dev
npm run build

echo "[5/9] Running migrations..."
php artisan migrate --force

echo "[6/9] Clearing stale caches BEFORE re-caching..."
# Order matters: clear FIRST so re-cache picks up post-pull source, not pre-pull cached output.
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear

echo "[7/9] Re-caching for production speed..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[8/9] Restarting queue worker..."
# Try Horizon first; fall back to plain queue:restart if Horizon isn't installed.
if php artisan list 2>/dev/null | grep -q "horizon:terminate"; then
  php artisan horizon:terminate
  sudo supervisorctl restart blastiq-horizon 2>/dev/null || true
else
  php artisan queue:restart
  sudo supervisorctl restart blastiq-worker:* 2>/dev/null || true
fi

echo "[9/9] Final verification..."
echo "  - Manifest exists?  $([ -f public/build/manifest.json ] && echo YES || echo NO)"
echo "  - public/hot gone?  $([ ! -f public/hot ] && echo YES || echo NO)"

echo ""
echo "=== Deploy complete ==="
