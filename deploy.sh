#!/bin/bash
set -e

echo "=== BlastIQ Deployment ==="

cd /var/www/blastiq

echo "Pulling latest code..."
git pull origin main

echo "Installing composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "Running migrations..."
php artisan migrate --force

echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Building frontend..."
npm ci --production
npm run build

echo "Restarting Horizon..."
php artisan horizon:terminate
sudo supervisorctl restart blastiq-horizon

echo "Clearing old caches..."
php artisan cache:clear

echo "=== Deploy complete ==="
