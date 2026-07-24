# Construction Plan — Fix All Identified Gaps

**Project:** laravel whatsapp_new (WhatsApp Cloud API + Africa's Talking voice + bulk email)
**Objective:** Close every identified gap, in dependency order — (A) activate + live-verify already-built flag-gated features and stand up prod infra, and (B) build a new per-employee two-way email client.
**Baseline:** `main`, clean, **536 tests green**. Full audit + review already done this repo.
**Mode:** git + `gh` available → feature branches + PRs. (Track A config steps may commit direct-to-main per repo convention; Track B — the large build — uses feature branches merged via PR.)

## Standing conventions (apply to every step)
- **Keep the suite green.** Test cmd: `/c/xamppp/php/php.exe artisan test` (local ZTS PHP; `php artisan serve` segfaults — use `serve.bat`).
- **No dead UI / no fake data.** A feature stays **flag-gated OFF** until it is live-verified end-to-end. Never ship a control that isn't backed.
- **Commit style:** conventional commits, **no attribution trailer** (repo/global setting).
- **Secrets:** encrypt at rest via the existing `encrypted` Eloquent cast (see `WhatsAppInstance.access_token`) / `Setting::getEncrypted`. Never log tokens.
- **Reuse, don't reinvent.** Precedents to mirror:
  - Provider-adapter pattern: `app/Services/EmailEvents/EmailEventParser` + `EmailEventParserFactory` (bounce parsers) → same shape for mail-account providers.
  - Multi-agent inbox: `Conversation` (`assigned_to_user_id`, `unread_count`, `last_message_at`), `RoundRobinAssigner`, spatie perms `conversations.view_all` / `view_assigned`.
  - Realtime: Reverb broadcast events (`app/Events/Calling/*`) + per-user private channels (`user.{id}`).
  - Webhook auth: secret path segment + `hash_equals`, fail-closed (`AfricasTalkingWebhookController`, `EmailWebhookController`).

## Two-letter legend
- **[agent]** = fully doable in-repo by a coding agent.
- **[user]** = needs a credential / live external service the user must provide, plus manual live verification. The agent scaffolds config + a verification script + docs; the user runs the live check.

---

## Dependency graph

```
TRACK A (activation/hardening)          TRACK B (new email client)
A1 infra baseline ──┐                    B1 data model + flag
                    ├─► A2 email delivery + bounce      │
A1 ────────────────►│                    B2 account connect (OAuth/IMAP)
                    └─► A3 voice live + call-flow + AI  │
                                          B3 inbound sync ──┬─► B4 inbox UI ──┐
A1 (Reverb/Horizon) is also a prereq for B3/B6 ───────────►│                 ├─► B6 realtime + polish
                                                           └─► B5 compose/reply
```
- **Parallelizable:** A2 ∥ A3 (independent env). **B1→B2 need no infra — start them concurrently with A1** (only B3's queued sync and B6's realtime depend on A1). B4/B5 both build on B3 but **share the thread-view component**, so they are *coordinated via a defined seam* (B4 owns the thread shell; B5 owns the composer/action wiring), not freely parallel.
- **Critical path (longest):** `max(A1, B1→B2) → B3 → B4 → B5 → B6`.

---

# TRACK A — Activate & harden what's already built

> These features are code-complete and tested; the gap is **live configuration + verification**. Nothing here is new logic — it's turning things on safely and proving they work against the real external services.

## A1 — Production infra baseline  `[agent]`+`[user]` · model: default · rollback: revert env/supervisor config
**Context brief.** The app needs Reverb (websockets), Horizon on Redis (queues), the cron scheduler (`schedule:run`), and the trusted-proxy/CSP/allowlist posture verified in the real topology. Deploy docs cover these (`docs/DEPLOYMENT.md`); this step executes + verifies them.
> **BLOCKER first (H2):** `config/horizon.php` defines only `production` + `local` environments. Horizon starts supervisors by matching `app.env`, so **on `APP_ENV=staging` no workers run and nothing processes** — which breaks A1's and B6's staging verification. **[agent]** add a `staging` block to `config/horizon.php` mirroring `production` (incl. the new `mail-sync` supervisor added in B3), **or** document that the staging host runs `APP_ENV=production`. Do this before any staging verification.
**Tasks.**
1. **[agent]** Add the `staging` Horizon environment (above).
2. Provision Redis; set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_QUEUE_RETRY_AFTER=360`.
3. Run Horizon under Supervisor (per-queue supervisors in `config/horizon.php`); confirm `messages,imports,default` all have workers.
4. Reverb: set `REVERB_*` + `REVERB_ALLOWED_ORIGINS=<app host>`; run the Reverb server; confirm a browser page opens the socket.
5. Cron: `* * * * * php artisan schedule:run` (drives campaign dispatch, template sync, `queue:monitor`, `horizon:snapshot`).
6. Behind nginx: set `TRUSTED_PROXIES` to the LB CIDR (not `*`); set `VOICE_WEBHOOK_IP_ALLOWLIST` to the provider CIDR(s) (defense-in-depth on the unauthenticated webhooks — L3); confirm `/up` returns 200 and deep-checks the DB, HSTS present over TLS, CSP present (non-`local`).
**Verify.** `/up` 200; `/horizon` shows active supervisors **in the target env**; a queued job runs; a wallboard tile updates live via Reverb; `curl -I` shows CSP + HSTS.
**Exit criteria.** Queues drain, realtime pushes, scheduler fires, health/headers correct in the deploy env (which has a matching Horizon environment).

## A2 — Bulk email delivery + bounce ingestion (live)  `[user]` · model: default · rollback: `MAIL_MAILER=log`
**Context brief.** Email code is complete but `MAIL_MAILER` defaults to `log` (nothing delivered); the launch UI warns about this. Bounce ingestion (`/webhooks/email/{provider}/{secret}`) is inert until `EMAIL_WEBHOOK_SECRET` is set + a provider points at it. Postmark parser ships; others are one class each.
**Tasks.**
1. Choose provider (recommend Postmark — cleanest, already has a parser). Set `MAIL_MAILER`, host/API creds, `MAIL_FROM_ADDRESS/NAME`.
2. Set `EMAIL_WEBHOOK_SECRET=$(openssl rand -hex 32)`; point the provider's Bounce + SpamComplaint webhooks at `/webhooks/email/postmark/<secret>`.
3. If provider ≠ Postmark: **[agent]** add a parser implementing `EmailEventParser`, register in `EmailEventParserFactory`, add tests (use `tests/Feature/Email/EmailWebhookTest.php` as the template — that's where the Postmark parser is covered), verify signature scheme.
**Verify.** Send a 1-recipient campaign to a real inbox → arrives; the "non-delivering transport" warning is gone. Fire a provider test bounce → address appears in `/email-campaigns/suppressions` as `bounce`. Full suite stays green.
**Exit criteria.** Real delivery confirmed; a live hard bounce auto-suppresses; soft bounce does not.

## A3 — Voice: live AT baseline + call-flow + recording/AI  `[user]` · model: default · rollback: flip `VOICE_*_ENABLED=false`
**This is a checklist of independent live-verification GATES, not one unit** (L6). Each gate = enable → live-verify → keep or revert. IVR/voicemail/queue/transfer/business-hours + recording+Gemini are all built and flag-gated `false` (`config/voice.php`); each must be verified against a **live Africa's Talking** account before enabling (Phase-0 gate). *Meta (WhatsApp Cloud) calling is OUT OF SCOPE — blocked on Meta GA; `config/voice.php` says its request shapes are doc-guessed/unverified and it can't yet carry agent audio.* (L1)
**Gate 0 — AT + media baseline (prereq for all voice).**
- Configure AT credentials + `AT_VOICE_WEBHOOK_SECRET`; point AT's voice callback at `/webhooks/africastalking/voice/<secret>`.
- **Provision TURN (M6):** set `VOICE_TURN_URLS` + `VOICE_TURN_USERNAME`/`VOICE_TURN_CREDENTIAL`. STUN-only leaves symmetric-NAT callers (corporate WiFi, some mobile carriers) with **dead air** — a single good-network test call would pass while real users get no audio. Verify from a symmetric-NAT network, not just the office.
- Place one real inbound + one outbound call end-to-end (audio flows both ways, wallboard updates).
**Gates 1-5 — one flag at a time, each with a live call:** `VOICE_BUSINESS_HOURS_ENABLED`, `VOICE_IVR_ENABLED`, `VOICE_QUEUE_ENABLED`, `VOICE_VOICEMAIL_ENABLED`, `VOICE_TRANSFER_ENABLED`.
**Gate 6 — recording + AI (only if wanted):** set `VOICE_CALL_RECORDING_ENABLED=true`, `GEMINI_API_KEY` (+ ffmpeg for webm→ogg), **and `VOICE_RECORDING_RETENTION_DAYS` to a real number (M5 — default `0` = keep forever, a compliance gap) and schedule `calls:prune-recordings`**. Verify a recording uploads (private disk) and a transcript/summary appears in `/workspace`.
**Verify.** Each enabled primitive behaves on a live call **incl. from a poor-NAT network**; recordings transcribe and prune on schedule; disabling a flag cleanly reverts to the basic dial path. Suite green.
**Exit criteria.** Every voice feature the org wants is live-verified (audio confirmed on real networks); retention is bounded; the rest stay OFF (not half-on).

---

# TRACK B — Per-employee two-way email client (NEW BUILD)

> The app today is one-directional bulk marketing email with **one** shared identity. This track adds a real multi-user mailbox: each employee connects **their own** account, sees **their own** inbox, and composes/replies **as themselves**. It reuses the WhatsApp inbox's assignment + permission + realtime machinery. All of it lives behind `config('mail_client.enabled', false)` until B6 is verified.

### Decision point (resolve before B1) — connection strategy
- **Recommended:** OAuth for **Gmail API** + **Microsoft Graph** (most orgs are Google Workspace / M365; OAuth avoids storing passwords, gives threads/labels/push natively).
- **Fallback adapter:** generic **IMAP+SMTP** (self-hosted / other providers) behind the same `MailAccountProvider` interface.
- Ship the interface + **one** provider first (pick per the org's actual mail host); add others as one adapter class each. Mirrors the `EmailEventParserFactory` pattern.

### Permission model (resolve before B1) — do NOT reuse the `email.*` namespace (M4)
`RolesAndPermissionsSeeder` already owns `email.view/create/edit/delete/send` for **bulk marketing campaigns**. Reusing `email.*` would conflate "can see marketing campaigns" with "can read every employee's private inbox" — a privilege-escalation ambiguity. Add a **distinct** namespace: `mailbox.view` (own inbox), `mailbox.view_all` (team inboxes), `mailbox.admin` (manage others' accounts). **Default visibility is PRIVATE-per-user** — the *inverse* of `conversations.*` (which defaults to shared). So the "reuse conversation scoping" reuse is the *mechanism* (assignment + query scoping), with **inverted default logic** (own-only unless `mailbox.view_all`), not a straight copy.

## B1 — Data model, schema, feature flag  `[agent]` · model: **strongest** · rollback: drop migrations (additive only)
**Context brief.** Foundation for everything else. Additive tables only — zero impact on existing features while `mail_client.enabled=false`.
**Tasks.**
1. `config/mail_client.php`: `enabled` (default false), `provider`, sync interval, per-account send rate.
2. Migrations + models (+ factories):
   - `email_accounts` — `user_id`, `email`, `provider`, `display_name`, encrypted `credentials` (OAuth tokens or IMAP/SMTP creds), `sync_state` (cursor/UIDVALIDITY/historyId), `last_synced_at`, `is_active`. Unique `(user_id, email)`; **soft-delete-safe resolver** (reuse the `Contact::firstOrNewIncludingTrashed` lesson).
   - `email_threads` — `email_account_id`, `subject`, `last_message_at`, `unread_count`, `folder`, `assigned_to_user_id` (nullable — reuse assignment), provider `thread_ref`.
   - `email_messages` — `email_thread_id`, `direction` (inbound/outbound), `message_id` (unique per account, dedup), `in_reply_to`, `references`, `from`, `to/cc/bcc` (json), `subject`, `body_html`/`body_text` (sanitized on render), `is_read`, `provider_ref`, `sent_at`/`received_at`.
   - `email_attachments` — `email_message_id`, `filename`, `mime`, `size`, private-disk `path`.
3. Encrypted cast on `credentials`, **and `protected $hidden = ['credentials']` (H1)** — the `encrypted` cast DECRYPTS on attribute access, so `toArray()`/JSON/**Livewire serialization** would emit plaintext tokens to the browser. Mirror the FULL `WhatsAppInstance` precedent (which hides `access_token`/`app_secret`), not just the cast. Never bind `credentials` to a public Livewire property.
4. Indexes on `(email_account_id, last_message_at)`, `(email_thread_id)`, `message_id`.
5. **Body retention (L4):** store `body_html`/`body_text` plaintext for search (defensible) — but make it a stated decision, and add a `mailbox:prune-messages` command + a `mail_client.retention_days` config so real mailbox PII isn't kept unbounded.
**Verify.** `migrate:fresh` + factories seed a thread with messages; models relate correctly; a test asserts `$account->toArray()` does NOT contain the credential plaintext; **suite green with the flag off** (nothing wired to UI yet).
**Exit criteria.** Schema + models + factories exist and are tested; credentials never serialize; app behaves identically with flag off.

## B2 — Per-user account connection (OAuth / IMAP)  `[agent]`+`[user]` · model: strongest · rollback: feature-flag off; revoke tokens
**Context brief.** Let each employee connect their mailbox. OAuth needs a Google/Microsoft app (client id/secret — `[user]`). IMAP/SMTP needs the user's host+creds.
**Tasks.**
1. `MailAccountProvider` interface: `authUrl()`, `exchangeCode()`, `refresh()`, `connectionTest()`; `GmailProvider`/`GraphProvider`/`ImapSmtpProvider`; a `MailAccountProviderFactory`.
2. Settings UI ("Email accounts", role-gated): connect flow (OAuth redirect or IMAP form), show connection status, disconnect (revoke + soft-delete). Encrypt creds on store; token-refresh job.
3. Guard: a user can only manage **their own** account unless `mailbox.admin` perm (new namespace — see the permission decision above).
**Verify.** Connect a real test account → `connectionTest()` passes; token refresh works; disconnect revokes. Tests: provider interface (mocked), account CRUD auth (a user can't touch another's account).
**Exit criteria.** An employee can connect + disconnect their own mailbox; creds encrypted; unauthorized cross-account access blocked.

## B3 — Inbound sync (fetch → thread → store)  `[agent]` · model: **strongest** · rollback: disable sync schedule; data is additive
**Context brief.** The hardest correctness piece: pull new mail per account, dedup, thread, store — idempotently, on a queue, at scale. **Unlike the SEND jobs, a sync/fetch is idempotent (dedup by `message_id`), so retries are SAFE and desirable — do NOT copy `$tries=1` here (M3).** Reuse only the chunk + upsert-including-trashed lessons.
**Tasks.**
1. `SyncEmailAccount` job (per account) on a dedicated `mail-sync` queue (add a Horizon supervisor **in every env incl. `staging`** — see A1/H2): incremental fetch via provider cursor (Gmail `historyId` / Graph delta / IMAP `UID`); parse MIME (attachments → private disk); dedup by `message_id`; thread by `In-Reply-To`/`References` (fallback: normalized subject + participants).
2. Idempotency: unique `message_id` per account; upsert-including-trashed. **Allow retries with backoff on transient/network errors** (a re-fetch can't double anything, so the send-job no-retry rationale doesn't apply).
3. **Cursor-invalidation fallback (M7) — the top real-world failure mode:** Gmail `historyId` 404/410 → full re-sync; Graph delta-token expiry → full re-sync; **IMAP `UIDVALIDITY` change invalidates the whole UID set → full re-sync**. Without this, sync silently stalls or dupes. Fixture-test each path.
4. Auth failure is terminal (not a transient retry): mark the account `needs_reauth`, stop syncing it, surface it in the UI.
5. Scheduler: fan out `SyncEmailAccount` for active accounts every N min (respect provider rate limits).
**Verify.** Send test emails (new thread + a reply) to a connected account → sync creates one thread with correctly ordered messages, attachments stored, no dupes on re-run. Tests: MIME/threading/dedup with fixture payloads (no live provider needed).
**Exit criteria.** Re-running sync is idempotent; replies thread correctly; attachments land on the private disk; auth failure is visible, not silent.

## B4 — Threaded inbox UI (thread SHELL)  `[agent]` · model: default · rollback: flag off (routes/nav hidden)
**Context brief.** Per-user mailbox UI, reusing the WhatsApp inbox UX. **Seam (M2): B4 owns the thread SHELL (folder/list/read view); B5 owns the composer + per-message actions inside a slot** — so B4 and B5 don't both rewrite the same Livewire component. Depends on B3 data.
**Tasks.**
1. Livewire inbox: folder list, thread list (unread/last-message sort, pagination), thread view (messages, sanitized HTML in a sandboxed iframe — reuse the email-preview sandbox), search (subject/from/body). Leave an explicit slot/seam for B5's composer + per-message actions.
2. Scoping (M4): default is **own accounts only**; `mailbox.view_all` sees the team's — this **inverts** the `conversations.*` default (shared). Same mechanism (query scoping), inverted default.
3. **No dead controls (L5):** the read/unread toggle updates local state only until B5 adds provider write-back — so either defer the toggle to B5 or label it and reconcile on next sync. Reply/forward stay hidden until B5 wires them.
**Verify.** Playwright/manual: inbox lists synced threads, opening marks read (locally), search filters, HTML body can't execute script (CSP + sandbox). Tests: Livewire scoping (user A can't see user B's threads), pagination, search.
**Exit criteria.** An employee reads their real synced mail in-app, safely, scoped to their permission; no unbacked control is visible.

## B5 — Compose / reply-as-self  `[agent]` · model: **strongest** · rollback: flag off
Outbound as the employee's own identity — the thing bulk-campaigns can't do. Depends on B3 (thread refs) + B2 (send transport). **Over-scoped for one PR (L6) → split B5a (transport/job) → B5b (UI into B4's slot).**
### B5a — Send transport + job
1. `SendUserEmail` job via the account's own SMTP/API (Gmail/Graph send); set `In-Reply-To`/`References` so replies thread on the recipient's side; attachments; store the sent copy as an outbound `email_messages` row + provider Sent folder.
2. Rate-limit per account; **`$tries=1` — CORRECT here** (no idempotency key on send; a retry would double-send). Contrast B3, where retries are safe.
3. Read-state / flag write-back to the provider (the piece B4's toggle deferred).
**Verify (B5a).** Send-payload assembly (In-Reply-To/References/attachments) via `Http::fake`/mailer fake; per-account auth; a live reply arrives threaded with `From` = the employee.
### B5b — Compose/reply/forward UI (renders into B4's seam)
4. Composer + per-message actions in B4's slot: to/cc/bcc arbitrary recipients, drafts, optimistic send with failure rollback.
**Verify (B5b).** Reply to a synced thread → arrives at the real recipient, threaded; a fresh compose to an arbitrary address arrives; a failed send rolls back the optimistic UI.
**Exit criteria.** Employees send + reply as themselves; messages thread correctly both sides; read-state syncs to the provider.

## B6 — Realtime, notifications, polish + FLAG ON  `[agent]`+`[user]` · model: default · rollback: flag off
**Context brief.** Make it feel live and flip the flag once end-to-end verified.
**Tasks.**
1. Reverb events on new inbound mail → live inbox update + unread badge (reuse `user.{id}` private channel pattern); optional notification.
2. Search indexing / pagination hardening at volume; empty/error states; accessibility.
3. Docs (`docs/EMAIL-CLIENT.md`): provider app setup, scopes, sync cadence, security model.
4. **Live end-to-end acceptance** on staging with ≥2 employees' real accounts; only then set `mail_client.enabled=true` in the target env.
**Verify.** New mail appears without refresh; two employees see only their own inboxes; full suite green; live acceptance signed off.
**Exit criteria.** Feature live-verified with real accounts and enabled; the app is genuinely a per-employee two-way email client.

---

## Cross-cutting risks & mitigations
- **Credential serialization (H1):** encrypted-cast tokens decrypt on access → `toArray()`/Livewire would leak plaintext. `$hidden` + never on a public Livewire prop; assert in a test.
- **OAuth app approval** (Gmail/Graph restricted scopes may need verification/admin consent) — start the provider-app + consent process early (parallel to B1).
- **Inbound sync correctness + cursor expiry (M7)** is the top technical risk → fixture-driven tests in B3 (threading, dedup, attachments, **and cursor-invalid → full-resync per provider**) before any live wiring; strongest model tier; retries-with-backoff (NOT `$tries=1`).
- **Voice dead-air (M6):** STUN-only → no audio for symmetric-NAT callers. Provision TURN and verify from a poor-NAT network before declaring voice live.
- **Storage/PII**: real mailboxes = sensitive data. Private disk only, encrypted creds, **bounded body/recording retention (purge commands)**, and per-user access enforced server-side (not just UI).
- **Permission collision (M4):** use the `mailbox.*` namespace, private-per-user default — do not overload the campaign `email.*` perms.
- **Scale**: sync + send are per-account queued jobs (dedicated `mail-sync` supervisor in every env); respect provider rate limits; `$tries=1` on **sends only**.
- **Scope creep**: B1–B6 deliver a *usable* client (inbox + reply). Calendar, contacts-sync, shared labels, signatures, filters are explicitly **out of scope** — future phase, don't smuggle them in.

## Sequencing summary
1. **Day one, in parallel:** **A1** (infra) and **B1→B2** (schema + account connect — need no infra). *(Corrected: B1/B2 do NOT wait on A1.)*
2. **A2/A3** (activation, `[user]`-gated) run alongside the build; **B3** (queued sync) starts once A1's Redis/Horizon is up.
3. **B4** (thread shell) → **B5a** (send transport) → **B5b** (composer into B4's slot) → **B6** flips the client on.
4. Every step ends green; every feature stays OFF until its live verification passes.
