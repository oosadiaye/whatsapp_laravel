# BlastIQ — WhatsApp Marketing Platform

Send Meta-approved WhatsApp Business templates as bulk marketing campaigns through the official **WhatsApp Cloud API**. Built on Laravel 12, Livewire 4, and Horizon.

---

## What it does

- **Per-customer Meta Cloud API instances** — each customer brings their own Meta Business account, you manage many under one platform
- **Sync approved templates** — pull every template Meta has registered for a WABA, with pagination, on-demand or every 15 minutes via scheduled sync
- **Campaign blasting** — pick template + contact group → personalized variable substitution → rate-limited queued send
- **Real delivery tracking** — HMAC-validated webhooks update message logs with sent/delivered/read/failed states using Meta's reported timestamps
- **Pre-flight safety rails** — campaign creation blocks PENDING/REJECTED templates so users don't burn sends on Meta-rejected templates
- **Encrypted credentials at rest** — access tokens, app secrets, and verify tokens are encrypted via `APP_KEY` so a DB dump leak is useless on its own

## Architecture in one paragraph

Direct integration with Meta's **WhatsApp Cloud API** at `graph.facebook.com/v20.0`. No third-party BSPs (Twilio, Vonage, 360dialog) — those add 20-40% markup for capabilities you already have direct. Each `WhatsAppInstance` model row carries one Meta phone number's credentials. The `WhatsAppMessenger` service is a thin facade that normalizes provider responses into a `SendResult` DTO so callers don't worry about response shape. Webhook events route per-instance via `/webhooks/whatsapp/{instance}` so each customer can use their own Meta App with its own `app_secret`.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.3, Laravel 12 |
| Frontend | Blade + Alpine.js + Tailwind |
| Real-time UI | Livewire v4 |
| Queue | Database driver (production: Redis + Horizon) |
| Auth | Laravel Breeze |
| Tests | PHPUnit 11 — 68 feature tests, 171 assertions |

---

## Quick start (local development)

**Prerequisites:** PHP 8.2+, Composer 2, Node 20+, MySQL or SQLite

```bash
# 1. Clone and install
git clone https://github.com/oosadiaye/whatsapp_laravel.git BlastIQ
cd BlastIQ
composer install
npm install

# 2. Configure env
cp .env.example .env
php artisan key:generate

# 3. Set up database (sqlite for fastest start)
touch database/database.sqlite
# In .env: DB_CONNECTION=sqlite, comment out other DB_* vars
php artisan migrate
php artisan db:seed

# 4. Build assets and run
npm run build
php artisan serve
```

Login at http://127.0.0.1:8000 with `admin@blastiq.com` / `password`.

## Onboarding a Meta WhatsApp Cloud API account

This is the prerequisite step for any customer. Walk them through it once and they're set up forever.

See **[docs/META_SETUP.md](docs/META_SETUP.md)** for the full step-by-step guide including:
- Creating a Meta Business account
- Setting up a WhatsApp Business app
- Provisioning a phone number
- Generating a permanent system-user access token
- Configuring webhooks against your BlastIQ instance

## Production deployment

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for production setup including:
- Server requirements (PHP 8.3, MySQL 8, Redis recommended)
- Nginx configuration
- Supervisor setup for the queue worker
- Cron entry for `schedule:run` (used by the 15-minute template-sync command)
- TLS / Let's Encrypt
- Common pitfalls (storage permissions, `APP_KEY` encryption mismatches)

---

## Project structure

```
app/
├── Console/Commands/
│   ├── DispatchScheduledCampaigns.php      Launch campaigns past their scheduled_at
│   └── SyncMessageTemplates.php             15-min template status refresh
├── Exceptions/
│   └── WhatsAppApiException.php             Single exception type for all Meta failures
├── Http/Controllers/
│   ├── CampaignController.php               Campaign CRUD + lifecycle (launch/pause/cancel)
│   ├── CloudWebhookController.php           Meta verify GET + events POST + HMAC validation
│   ├── ContactController.php                Contact import + edit
│   ├── ContactGroupController.php           Group CRUD
│   ├── DashboardController.php              Stats + recent activity
│   ├── MessageTemplateController.php        Sync-from-Meta, submit-to-Meta, local CRUD
│   ├── SettingsController.php               Sending defaults (rate, delays, country code)
│   └── WhatsAppInstanceController.php       Instance setup with Meta credential probe
├── Jobs/
│   ├── CampaignBatchDispatch.php            Fans a campaign out into per-contact send jobs
│   ├── ProcessContactImport.php             Async CSV/XLSX import
│   └── SendWhatsAppMessage.php              One message; picks template/media/text path
├── Livewire/
│   ├── CampaignStatus.php                   wire:poll.3s — live campaign stats
│   └── MessageLogsTable.php                 wire:poll.5s — paginated, filterable logs
├── Models/
│   ├── Campaign.php
│   ├── Contact.php / ContactGroup.php
│   ├── MessageLog.php
│   ├── MessageTemplate.php
│   ├── Setting.php
│   ├── User.php
│   └── WhatsAppInstance.php                 Encrypted credential storage
└── Services/
    ├── CampaignService.php                  Launch / pause / resume / cancel logic
    ├── ContactImportService.php             Phone normalization + personalization
    ├── SendResult.php                       Normalized DTO from any send call
    ├── WhatsAppCloudApiService.php          Pure HTTP wrapper for graph.facebook.com
    └── WhatsAppMessenger.php                Facade — single entry point for all sends
```

## Running tests

```bash
php artisan test                             # all tests, fast feedback
php artisan test --filter Cloud              # just the Cloud API service tests
php artisan test --coverage --min=80         # with coverage report
```

The full suite runs in ~5 seconds (in-memory SQLite, sync queue, no real HTTP).

## Operational commands

```bash
# Queue worker (in production: managed by Supervisor)
php artisan queue:work --queue=messages,default --tries=3

# Manual trigger of scheduled jobs (normally fired by cron)
php artisan schedule:run

# Refresh template statuses from Meta on demand
php artisan templates:sync-status            # all eligible instances
php artisan templates:sync-status --instance=5

# Launch any campaigns whose scheduled_at has passed
php artisan campaigns:dispatch-scheduled
```

---

## Key constraints to know

1. **24-hour conversation window**: WhatsApp prohibits freeform marketing to contacts who haven't messaged you in the last 24 hours. The campaign form warns users when no template is picked, and blocks PENDING/REJECTED templates entirely. Template-based sends are the production path.
2. **Meta template approval is asynchronous**: Templates submitted via `submitToMeta()` land in PENDING and become APPROVED only after Meta's review (minutes to hours). The `templates:sync-status` scheduled command refreshes status every 15 minutes so the UI reflects reality without manual re-syncing.
3. **Rate limits scale with quality rating**: New numbers start at TIER_50 (50 unique users / 24h). Tier increases automatically with good engagement; degrade with low quality rating. Visible on the instance show page.
4. **Phone numbers are E.164**: The service strips `+`, spaces, and dashes before sending — input format doesn't matter as long as country code is included.

## License

MIT — fork freely.
