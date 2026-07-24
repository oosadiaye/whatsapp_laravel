# Per-Employee Email Client

A two-way email client: each employee connects their own mailbox (IMAP for
receiving, SMTP for sending) and works a threaded inbox in-app — read, reply,
reply-all, forward, compose, with attachments both ways. Distinct from the bulk
**email campaigns** feature (one shared marketing identity, one-directional).

Built across plan steps **B1–B6** on the `feat/email-client` branch. The whole
feature is **gated OFF by default** (`config('mail_client.enabled')`, env
`MAIL_CLIENT_ENABLED`) and must stay off until the live-verification checklist
below passes against a real account.

---

## Architecture at a glance

| Concern | Where |
|---|---|
| Data | `email_accounts`, `email_threads`, `email_messages`, `email_attachments` |
| Provider adapter | `App\Services\MailClient\MailAccountProviderFactory` → `ImapSmtpProvider` (connect), `ImapFetcher` (receive), `SmtpSender` (send) |
| Inbound sync | `SyncEmailAccount` job (queue `mail-sync`, retries+backoff) ← `EmailSyncService` (dedup, threading, attachments, cursor) |
| Outbound send | `SendUserEmail` job (queue `mail-send`, **tries=1**) ← `UserMail` mailable |
| UI | `App\Livewire\Mailbox\Inbox` (+ `Concerns\WithCompose`), page `mailbox.inbox` |
| Realtime | `App\Events\Mailbox\MailReceived` → `PrivateChannel('user.{id}')`, alias `mail.received` |
| Downloads | `MailboxController@downloadAttachment` (streams off the private disk) |

### Configuration (`config/mail_client.php`)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `MAIL_CLIENT_ENABLED` | `false` | Master switch. Off ⇒ routes 404, nav hidden, sync/scheduler skip. |
| `provider` | `MAIL_CLIENT_PROVIDER` | `imap` | Adapter slug (only `imap` implemented; Gmail/Graph are future adapters). |
| `sync_interval_minutes` | `MAIL_CLIENT_SYNC_INTERVAL` | `2` | `mailbox:sync` cron cadence. |
| `send_rate_per_minute` | `MAIL_CLIENT_SEND_RATE` | `30` | Reserved for per-account send throttling. |
| `retention_days` | `MAIL_CLIENT_RETENTION_DAYS` | `0` | `0` = keep forever. |

### Security posture (already enforced in code)

- **Credentials** are stored `encrypted:array` on `email_accounts.credentials` and
  are in the model's `$hidden` — never serialized to Livewire/JSON.
- **Private-per-user** by default: a user sees only their own accounts' threads;
  `mailbox.view_all` widens reads to the team (inverse of `conversations.*`).
- **Send-as-self**: you may only send from an account you OWN. `mailbox.view_all`
  lets a manager READ a colleague's thread but NOT reply as them (403).
- **Untrusted inbound HTML** renders in a sandboxed `srcdoc` iframe (no scripts /
  same-origin), safe under the app CSP (`frame-src 'self'`).
- **Attachments** live on the private `local` disk (outside the web root); every
  download is re-authorized (own account, or `view_all`).
- Authorization runs on **every Livewire render/action**, not just mount —
  Livewire updates bypass the route middleware.

---

## Enabling in production

1. **Migrate** (tables ship with the branch):
   `php artisan migrate`
2. **Seed the permissions** (adds `mailbox.view`, `mailbox.view_all`, `mailbox.admin`):
   `php artisan db:seed --class=RolesAndPermissionsSeeder`
3. **Grant access** — assign `mailbox.view` to the employees who get a mailbox
   (agents already get it via the seeder allowlist; managers/admins get
   `view_all`/`admin`).
4. **Run the workers** — Horizon must run the new supervisors:
   - `mail-sync-supervisor` (queue `mail-sync`, tries 3)
   - `mail-send-supervisor` (queue `mail-send`, tries 1)
   Ensure any **staging** Horizon env also lists these queues.
5. **Scheduler** — `php artisan schedule:work` (or cron) drives
   `mailbox:sync` every `sync_interval_minutes` (only when the flag is on).
6. **Reverb** (realtime) — run `php artisan reverb:start` and set the Reverb/Echo
   env. Without it the inbox still works; it just falls back to manual refresh.
7. **Flip the flag** — set `MAIL_CLIENT_ENABLED=true` **only after** the live
   checklist below passes.

An employee then connects a mailbox at **/mailbox/accounts** (host/port/
encryption + username/password for IMAP and SMTP). A successful connection test
marks the account active; an auth failure marks it `needs_reauth`.

---

## Live-verification checklist (the B6 gate)

Everything is unit/feature-tested with stubbed IMAP/SMTP, so the ONE thing never
run against a real server is `ImapFetcher`'s webklex attribute access. Do this
with a throwaway/real test mailbox **before flipping the flag on**:

- [ ] **Connect** — add the account at `/mailbox/accounts`; the connection test
      passes (account shows active, not `needs_reauth`).
- [ ] **Receive** — send an email INTO that mailbox from elsewhere, then run
      `php artisan mailbox:sync`. Confirm the message appears in `/mailbox`
      (`email_messages` row, correct subject/from/body, attachments downloadable).
      This exercises `ImapFetcher` end-to-end — watch for null/attribute errors.
- [ ] **Thread** — reply to that message from the external side; re-sync; confirm
      it lands in the SAME thread (header threading via In-Reply-To/References).
- [ ] **Send** — reply from within the app; confirm the recipient receives it,
      the From is the employee's address, and it threads on their side.
- [ ] **Attachment round-trip** — receive one with an attachment (download it) and
      send one with an attachment.
- [ ] **Realtime** — with Reverb running and the inbox open, a freshly-synced
      inbound message refreshes the list without a manual reload.
- [ ] **Re-sync idempotency** — run `mailbox:sync` twice; no duplicate messages,
      no duplicate realtime notifications.

Only when all boxes are checked: `MAIL_CLIENT_ENABLED=true`.

---

## Operational notes

- **Send is never retried** (`SendUserEmail::$tries = 1`). A send has no wire
  idempotency key, so a retry would double-deliver. A failed send surfaces as a
  failed job for the employee to resend — check Horizon failed jobs.
- **Sync IS retried** with backoff (`SyncEmailAccount`, tries 3) — a re-fetch is
  idempotent (dedup by Message-ID).
- **Auth failures are terminal** — a bad-credential sync flags the account
  `needs_reauth` and stops syncing it until the employee reconnects.
- **Mark-read is currently local** — opening a thread marks it read in-app; IMAP
  `\Seen` write-back to the server is a documented future add.
- **Threading is header-based** (In-Reply-To / References). A reply that stripped
  those headers starts a new thread; subject-similarity fallback is a future add.
