# Production Deployment

## Server requirements

| Component | Minimum | Recommended |
|---|---|---|
| OS | Linux (any) | Ubuntu 22.04+ or RHEL 9+ |
| PHP | 8.2 | 8.3 |
| PHP extensions | bcmath, ctype, curl, fileinfo, mbstring, openssl, pdo_mysql, tokenizer, xml | + redis, intl, gd, zip |
| Composer | 2.5+ | 2.7+ |
| Node | 18+ (only for build) | 20+ |
| Database | MySQL 8.0 / MariaDB 10.6 / PostgreSQL 14+ | MySQL 8.0 |
| Cache + Queue | Database driver works fine | Redis 6+ |
| Process manager | Systemd or Supervisor | Supervisor |
| Web server | Apache 2.4 or Nginx 1.20+ | Nginx + PHP-FPM |
| TLS | Required (Meta won't accept HTTP webhooks) | Let's Encrypt via Certbot |

CPU/RAM: a 2-core / 4 GB VPS handles thousands of campaigns/day comfortably. Bottleneck is usually outbound rate limits from Meta, not your server.

---

## Initial deploy

### 1. Clone + install

```bash
cd /home
git clone https://github.com/oosadiaye/whatsapp_laravel.git BlastIQ
cd BlastIQ
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

### 2. Environment

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Critical values to set in `.env`:

```ini
APP_NAME=BlastIQ
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_DATABASE=blastiq
DB_USERNAME=blastiq_user
DB_PASSWORD=<strong password>

# If using Redis:
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# If staying on database driver (simpler):
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
```

> ⚠️ **Never lose `APP_KEY`** after first launch. It's used to encrypt every customer's Cloud API access token + app secret. If APP_KEY changes, those credentials become unrecoverable garbage and every customer has to re-paste their Meta credentials.

### 3. Database setup

```bash
mysql -u root -p
> CREATE DATABASE blastiq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> CREATE USER 'blastiq_user'@'localhost' IDENTIFIED BY '<password>';
> GRANT ALL ON blastiq.* TO 'blastiq_user'@'localhost';
> FLUSH PRIVILEGES;
> EXIT;

php artisan migrate --force
php artisan db:seed --force      # creates admin@blastiq.com / password — change immediately
```

### 4. Storage permissions

```bash
mkdir -p storage/framework/{sessions,views,cache/data}
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Replace 'www-data' with your webserver user (apache, nginx, oosadiaye, etc.)
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 5. Build optimized caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Nginx configuration

`/etc/nginx/sites-available/blastiq`:

```nginx
server {
    listen 80;
    server_name blast.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name blast.example.com;
    root /home/BlastIQ/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/blast.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/blast.example.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains";

    charset utf-8;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/blastiq /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### TLS via Certbot

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d blast.example.com
```

Auto-renews every 90 days via the certbot timer.

---

## Queue worker (Supervisor)

`/etc/supervisor/conf.d/blastiq-worker.conf`:

```ini
[program:blastiq-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/BlastIQ/artisan queue:work --queue=messages,default --tries=3 --backoff=30 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/blastiq-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start blastiq-worker:*
```

> Two worker processes (`numprocs=2`) is fine for low-medium volume. Bump if your queue backs up.

### Optional: Horizon (for Redis-backed queues)

If you went with `QUEUE_CONNECTION=redis`, prefer Horizon over raw `queue:work`:

```bash
php artisan horizon:install        # one-time, already done in this repo
php artisan horizon                # in production: replace queue:work command in supervisor with `horizon`
```

Visit `/horizon` to see queue activity, throughput, failed jobs.

---

## Cron — schedule:run

Laravel's scheduled commands (the 15-min `templates:sync-status` and the every-minute `campaigns:dispatch-scheduled`) need a cron entry:

```bash
crontab -e -u www-data
```

Add:

```
* * * * * cd /home/BlastIQ && php artisan schedule:run >> /dev/null 2>&1
```

---

## Verifying the deploy

After everything is up:

```bash
# 1. Test login page
curl -I https://blast.example.com
# → expect 302 redirect to /login

# 2. Test webhook verify endpoint (with bogus instance — should 404, proving routing works)
curl -I https://blast.example.com/webhooks/whatsapp/999999
# → expect 404 (not 500 or 502)

# 3. Run the test suite
cd /home/BlastIQ
php artisan test
# → expect 68 passing

# 4. Check queue worker
sudo supervisorctl status
# → expect blastiq-worker:* RUNNING

# 5. Check schedule:run is firing
tail -f storage/logs/laravel.log
# every minute you should see scheduled commands logging (or no errors at minimum)
```

---

## Updating

Standard Laravel update flow:

```bash
cd /home/BlastIQ
git pull origin main

composer install --no-dev --optimize-autoloader
npm install
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo supervisorctl restart blastiq-worker:*
```

> The supervisor restart at the end is critical — workers cache PHP code in memory, so they keep running old code until restarted.

---

## Troubleshooting

### "Please provide a valid cache path"

`storage/framework/views/` doesn't exist or isn't writable. See [Storage permissions](#4-storage-permissions).

### "Class 'Redis' not found"

Either install `php-redis` extension (`sudo apt install php8.3-redis && systemctl restart php8.3-fpm`) **or** switch `.env` to `predis` (pure-PHP, slower) by setting `REDIS_CLIENT=predis`.

### Webhook verification fails

- Confirm the URL in Meta's webhook config matches exactly what BlastIQ shows on the instance page
- Confirm the verify token matches exactly (case-sensitive, no trailing whitespace)
- Confirm HTTPS works without certificate warnings (`curl -v https://blast.example.com` should show valid cert)
- Check nginx + PHP-FPM logs: `tail -f /var/log/nginx/error.log /var/log/php8.3-fpm.log`

### Webhook signature mismatch

Storage permission issue is rare; more likely the App Secret in BlastIQ doesn't match what's in the Meta App's Settings → Basic. Re-copy from Meta dashboard.

### Customer X's Cloud API calls fail with 401 / Invalid OAuth

Their access token expired (24h temp tokens) or was revoked. They need to re-do Step 6 of META_SETUP.md and paste a fresh permanent System User token.

### Send job is stuck / not processing

```bash
php artisan queue:failed              # see what failed
php artisan queue:retry all           # retry everything
php artisan queue:flush               # nuclear option: drop all failed jobs
sudo supervisorctl restart blastiq-worker:*
```

### Wholly stuck — restore last known good

```bash
git log --oneline | head -5           # find the commit hash before the breakage
git checkout <last-good-commit>
composer install --no-dev
php artisan config:clear
php artisan config:cache
sudo supervisorctl restart blastiq-worker:*
```

---

## Backup recommendations

Daily backups of:

1. **Database** — contains everything (instances, contacts, message logs, campaigns)
   ```bash
   mysqldump -u root -p blastiq | gzip > /backups/blastiq-$(date +%F).sql.gz
   ```
2. **Storage** — uploaded media (campaign attachments, profile photos)
   ```bash
   tar czf /backups/blastiq-storage-$(date +%F).tar.gz /home/BlastIQ/storage/app
   ```
3. **`.env`** — APP_KEY is irreplaceable
   ```bash
   cp /home/BlastIQ/.env /backups/.env-blastiq-$(date +%F)
   ```

7-day retention is fine for day-to-day; archive monthly snapshots indefinitely if storage is cheap.
