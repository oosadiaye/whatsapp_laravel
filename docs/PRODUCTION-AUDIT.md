# Production Readiness Audit

**Date:** 2026-07-16
**Scope:** Full application — WhatsApp Cloud API marketing, Africa's Talking voice, bulk email.
**Method:** Three parallel audits (security · data & performance · deployment/config/reliability), then every Blocker/High claim re-verified by hand against source.

> This is an **audit**, not a set of applied changes. Nothing here has been fixed yet.
> IDs are stable so you can say e.g. "fix B1, B2, H2, H4".

---

## Summary

| Sev | ID | Finding | Location |
|-----|----|---------|----------|
| 🔴 Blocker | B1 | Soft-deleted contact + un-scoped unique index → inbound webhook 500s & lost messages/calls | `contacts` migration + 5 write paths |
| 🔴 Blocker | B2 | Privilege escalation / account takeover in user management | `UserController::update/store` |
| 🟠 High | H1 | HTML injection into outbound campaign emails via unescaped contact name | `CampaignEmail::personalize` |
| 🟠 High | H2 | `EmailCampaignDispatch` has no idempotency guard → duplicate bulk-email blast on retry | `EmailCampaignDispatch::handle` |
| 🟠 High | H3 | `SendCampaignEmail` missing per-log status guard → re-send on retry | `SendCampaignEmail::handle` |
| 🟠 High | H4 | Deploy worker omits `imports` queue → imports silently never process | `DEPLOYMENT.md` worker cmd |
| 🟠 High | H5 | `TrustProxies` unconfigured → breaks webhook IP allowlist + HTTPS/HSTS detection behind nginx | `bootstrap/app.php` |
| 🟠 High | H6 | Queue `retry_after` (90s) < job timeouts (120–300s) → double job execution | `config/queue.php` / Horizon |
| 🟡 Medium | M1 | Campaign fan-out unbatched inside a 120s job timeout → half-sent campaigns at scale | `CampaignBatchDispatch` |
| 🟡 Medium | M2 | Non-sargable `whereDate()` in hot paths defeats indexes | `CallController`, `Wallboard` |
| 🟡 Medium | M3 | `message_logs.sent_at` unindexed → dashboard full-scans | `DashboardController` |
| 🟡 Medium | M4 | `campaigns` table missing `(status, scheduled_at)` index | `campaigns` migration |
| 🟡 Medium | M5 | Unbounded `->get()` in log export + email recipients (runs on every campaign view) | `CampaignController`, `EmailCampaignService` |
| 🟡 Medium | M6 | CSV/XLSX import loads whole file into memory + per-row N+1 settings query | `ContactImportService` |
| 🟡 Medium | M7 | No CSP / HSTS headers | middleware / nginx |
| 🟡 Medium | M8 | CDN scripts loaded without SRI | `layouts/app.blade.php` |
| 🟡 Medium | M9 | Reverb `allowed_origins = ['*']` | `config/reverb.php` |
| 🟡 Medium | M10 | `CampaignBatchDispatch` can strand a campaign in RUNNING on partial fan-out | `CampaignBatchDispatch` |
| 🟡 Medium | M11 | `MAIL_MAILER=log` + empty SMTP creds → email silently no-ops | `.env` / config |
| 🟢 Low | L1 | TLS verification disabled on outbound media probes | `CampaignController` |
| 🟢 Low | L2 | Call-recording upload trusts client MIME, no allowlist | `CallController::storeRecording` |
| 🟢 Low | L3 | No transaction around fan-out / import loops | `CampaignBatchDispatch`, `ContactController` |
| 🟢 Low | L4 | `message_templates` shares B1's soft-delete/unique class of bug | `SyncMessageTemplates` |
| 🟢 Low | L5 | `PruneCallRecordings` unindexed daily scan | `PruneCallRecordings` |
| 🟢 Low | L6 | redis-vs-database queue config inconsistency across config/docs | config + docs |
| 🟢 Low | L7 | Single worker → queue starvation between message/call/import queues | deploy |
| 🟢 Low | L8 | AT REST calls have no timeout/retry | `AfricasTalkingVoiceService` |
| 🟢 Low | L9 | Horizon deploy path + `horizon:snapshot` schedule missing | deploy / scheduler |
| 🟢 Low | L10 | Observability gap: only `/up`, no error tracking | app-wide |
| 🟢 Low | L11 | `CallController::index` aggregates "today" in PHP not SQL | `CallController` |
| 🟢 Low | L12 | `Setting::get()` uncached — repeated per-request DB hits | `Setting` |
| 🟢 Low | L13 | `DEPLOYMENT.md` staleness (storage:link/cron/test count) | docs |

**Verdict:** Not production-ready as-is. **B1, B2, H4, H5** are the true go-live gates (data loss, account takeover, silent feature outage, broken webhook auth). **H1–H3, H6** are correctness/security bugs that should ship in the same pass. The Mediums are real at the stated "tens of thousands of contacts" scale; the Lows are hardening.

---

## 🔴 Blockers

### B1 — Soft-deleted contact + un-scoped unique index crashes inbound webhooks

**Where:**
- Root cause: [database/migrations/2024_01_01_100005_create_contacts_table.php:19-21](../database/migrations/2024_01_01_100005_create_contacts_table.php#L19-L21) — `softDeletes()` **and** a plain `unique(['user_id','phone'])`.
- Unguarded write paths (no `withTrashed()`):
  - [app/Services/InboundMessageProcessor.php:139](../app/Services/InboundMessageProcessor.php#L139) — inbound WhatsApp
  - [app/Services/InboundCallProcessor.php:231](../app/Services/InboundCallProcessor.php#L231) — inbound AT call
  - [app/Http/Controllers/AfricasTalkingWebhookController.php:130](../app/Http/Controllers/AfricasTalkingWebhookController.php#L130) — AT call webhook
  - [app/Services/ContactImportService.php:85](../app/Services/ContactImportService.php#L85) — CSV/XLSX import
  - [app/Http/Controllers/ContactController.php:278](../app/Http/Controllers/ContactController.php#L278) — manual paste import

**Impact:** Eloquent's `SoftDeletes` global scope hides trashed rows from the `firstOrCreate`/`updateOrCreate` lookup, but the **database** unique constraint is not scoped to `deleted_at`. So the moment a previously-deleted contact's number messages or calls in again — or is re-imported — the `create()` throws an uncaught `QueryException`. In the webhook paths that is an unhandled 500: **the inbound message/call is lost with no record**, and Meta/AT will keep retrying a request that can never succeed. Bulk imports die mid-batch with partial state. No test covers this.

**Fix (either):**
- Look up `Contact::withTrashed()->firstOrNew(...)`, `restore()` if trashed, then set attributes and `save()` — in all five call sites. (Recommended — preserves history.)
- Or drop `softDeletes()` from `contacts` if hard-delete is acceptable, and change `ContactController::destroy` accordingly.

Add a regression test: soft-delete a contact, then replay an inbound message from the same number → expect 200 + restored contact.

---

### B2 — Privilege escalation & account takeover via user management

**Where:** [app/Http/Controllers/UserController.php:80-111](../app/Http/Controllers/UserController.php#L80-L111) (`update`), [:44-69](../app/Http/Controllers/UserController.php#L44-L69) (`store`); role grants at [database/seeders/RolesAndPermissionsSeeder.php:127-130](../database/seeders/RolesAndPermissionsSeeder.php#L127-L130).

**Impact:** The `admin` role is `super_admin` minus only `users.create`/`users.delete` — it **keeps `users.edit`**. `update()`:
- Accepts any `role` that merely `exists:roles,name` ([:86](../app/Http/Controllers/UserController.php#L86)) — the edit/create views list every role including `super_admin`, unfiltered.
- Accepts an arbitrary new `password` for the **target** user ([:85](../app/Http/Controllers/UserController.php#L85), [:102-104](../app/Http/Controllers/UserController.php#L102-L104)) with no re-authentication of the actor.
- The only guard ([:90-94](../app/Http/Controllers/UserController.php#L90-L94)) blocks changing **your own** role — it does nothing for other accounts.

Net: any `admin` can silently reset any other user's password (including a `super_admin`'s) and/or promote any account to `super_admin`. Horizontal → vertical privilege escalation, defeating the "admin = super_admin minus user create/delete" boundary the class docblock claims.

**Fix:**
- Server-side restrict the assignable role set to what the actor may grant (only `super_admin` may assign or edit the `super_admin` role; `admin` may not edit a user whose current *or* requested role is `super_admin`).
- Require `current_password` (actor's) or an explicit password-reset flow before an admin sets a **different** user's password.
- Add tests: `admin` promoting someone to `super_admin` → 403; `admin` editing a `super_admin` → 403.

---

## 🟠 High

### H1 — HTML injection into outbound emails via contact name

**Where:** [app/Mail/CampaignEmail.php:60-68](../app/Mail/CampaignEmail.php#L60-L68) (`personalize`), rendered at [:70-82](../app/Mail/CampaignEmail.php#L70-L82), sent from [app/Jobs/SendCampaignEmail.php:54-56](../app/Jobs/SendCampaignEmail.php#L54-L56).

**Impact:** `{{name}}`/`{{email}}` are substituted into `body_html` via `strtr()` with the raw `Contact::name` — attacker-influenceable free text (CSV import, manual entry, WhatsApp profile name) — with **no escaping**, then sent as the raw HTML body of a real email. A contact named `<a href="http://evil/">click</a>` or `<img src=x onerror=...>` gets that markup injected into every campaign email to them. The footer two lines down correctly uses `e($sender)`/`e($url)` — the discipline exists, it was just missed in `personalize()`.

**Fix:** `e()` the name/email before substitution into `body_html` (a separate escaped variant for the HTML body; the subject-line call can stay raw since it isn't HTML-rendered).

### H2 — `EmailCampaignDispatch` has no idempotency guard

**Where:** [app/Jobs/EmailCampaignDispatch.php:30-69](../app/Jobs/EmailCampaignDispatch.php#L30-L69).

**Impact:** `handle()` guards only null/CANCELLED, then unconditionally re-computes recipients and creates a **new** `EmailLog` + dispatches a `SendCampaignEmail` per recipient. If the job is released and re-run — which H6's `retry_after` < runtime makes likely for a large fan-out — it produces a **second full batch**: duplicate emails to the entire audience. (Regression introduced with the email module this session; the WhatsApp sibling avoids this.)

**Fix:** Atomically claim the campaign before fanning out — e.g. a conditional `update` transitioning `QUEUED → SENDING` and bail if zero rows changed, or a guard at the top: `if ($campaign->status !== EmailCampaign::STATUS_QUEUED) return;` (confirm the dispatch-time status matches).

### H3 — `SendCampaignEmail` missing per-log status guard

**Where:** [app/Jobs/SendCampaignEmail.php:33-68](../app/Jobs/SendCampaignEmail.php#L33-L68).

**Impact:** No `status !== QUEUED` bail at the top. A worker-timeout release (H6) or a throw in the post-send `completeIfDone()` ([:67](../app/Jobs/SendCampaignEmail.php#L67), outside the try/catch) re-runs the job and **re-sends** to that recipient — despite the "at-most-once" comment. Its WhatsApp sibling [SendWhatsAppMessage.php:67-70](../app/Jobs/SendWhatsAppMessage.php#L67-L70) has exactly the guard this one lacks.

**Fix:** Mirror the WhatsApp job — at the top of `handle()`: `$log->refresh(); if ($log->status !== EmailLog::STATUS_QUEUED) return;`.

### H4 — Deploy worker omits the `imports` queue

**Where:** `DEPLOYMENT.md` worker command (~line 180).

**Impact:** The documented `queue:work` / Horizon supervisor lists `messages`, `default`, etc. but not `imports`, the queue `ContactImportService` dispatches onto. Follow the docs verbatim and **contact imports never run** — they sit queued forever with no error.

**Fix:** Add `imports` (and audit against every `onQueue(...)` in the codebase) to the worker queue list in both `DEPLOYMENT.md` and the Horizon supervisor config; reconcile with L6.

### H5 — `TrustProxies` not configured

**Where:** [bootstrap/app.php:14](../bootstrap/app.php#L14) `withMiddleware` (no `$middleware->trustProxies(...)`).

**Impact:** Behind nginx/load balancer, Laravel sees the proxy's IP and `http` scheme, not the client's. That (a) makes the webhook source-IP allowlist (`AllowedWebhookIps`) match the proxy instead of Meta/AT — so an IP allowlist is ineffective or wrong, and (b) breaks HTTPS detection, which undermines secure-cookie and any HSTS/redirect logic (ties to M7). Login rate-limiting is also keyed partly on IP.

**Fix:** Configure `trustProxies(at: '*' or the LB CIDR)` with the correct trusted headers in `bootstrap/app.php`. Document the exact proxy CIDR in `DEPLOYMENT.md`.

### H6 — Queue `retry_after` shorter than job timeouts

**Where:** `config/queue.php` redis `retry_after` (90s) vs job timeouts of 120/180/300s (Horizon supervisors / job `$timeout`).

**Impact:** When a job legitimately runs longer than `retry_after`, the queue assumes it died and hands the **same** job to another worker while the first is still running → double execution. Compounds H2/H3 (duplicate emails) and any non-idempotent job.

**Fix:** Set `retry_after` strictly greater than the longest job timeout (e.g. `REDIS_QUEUE_RETRY_AFTER=360`). General rule: `retry_after > max(job timeout)`.

---

## 🟡 Medium

### M1 — Campaign fan-out is unbatched inside a 120s timeout
[app/Jobs/CampaignBatchDispatch.php:47-92](../app/Jobs/CampaignBatchDispatch.php#L47-L92): loads the whole audience via `->get()`, then loops one `MessageLog::create()` + one `SendWhatsAppMessage::dispatch()` per contact — up to 2N round trips in a single job under the `default` supervisor's 120s timeout ([config/horizon.php:244](../config/horizon.php#L244)). At tens of thousands of contacts this risks timeout + a half-fanned-out campaign. **Fix:** chunked bulk `insert()` for `MessageLog`, dispatch sends via `Bus::batch()` or a paginated secondary job; set an explicit `$timeout`.

### M2 — Non-sargable `whereDate()` in hot paths
[app/Http/Controllers/CallController.php:59](../app/Http/Controllers/CallController.php#L59),[:72](../app/Http/Controllers/CallController.php#L72) and [app/Livewire/Wallboard.php:33](../app/Livewire/Wallboard.php#L33) wrap the indexed `created_at` in `whereDate()`, defeating the `(direction,status,created_at)` index — on every `/calls` load and every Wallboard poll. **Fix:** `->where('created_at','>=',today())->where('created_at','<',today()->addDay())`.

### M3 — `message_logs.sent_at` unindexed
[app/Http/Controllers/DashboardController.php:36](../app/Http/Controllers/DashboardController.php#L36),[:44-49](../app/Http/Controllers/DashboardController.php#L44-L49) filter/`GROUP BY DATE(sent_at)` but only `(campaign_id,status)` + `whatsapp_message_id` are indexed. Every dashboard load full-scans `message_logs` as it grows. **Fix:** add an index on `sent_at` (or `(status, sent_at)`).

### M4 — `campaigns` missing `(status, scheduled_at)` index
Its sibling `email_campaigns` has it ([2026_07_14_000002:49](../database/migrations/2026_07_14_000002_create_email_campaigns_table.php#L49)); `2024_01_01_100008_create_campaigns_table.php` has none. `DispatchScheduledCampaigns` (scheduled) and `CampaignController::clearQueue()` scan by `status` unindexed. **Fix:** migration adding `index(['status','scheduled_at'])`.

### M5 — Unbounded `->get()` on scaling tables
[app/Http/Controllers/CampaignController.php:550-552](../app/Http/Controllers/CampaignController.php#L550-L552) `exportLogs()` materializes every log before the CSV stream starts (negates `streamDownload`). [app/Services/EmailCampaignService.php:25-43](../app/Services/EmailCampaignService.php#L25-L43) `recipients()` loads all matching contacts then does PHP-side `reject()` against a fully-plucked suppression list — and it runs on **every** `EmailCampaignController::show` page view ([:63](../app/Http/Controllers/EmailCampaignController.php#L63)), not just at send. **Fix:** `cursor()`/`chunk()` the export; push suppression into SQL (`whereNotIn('email', EmailSuppression::select('email'))`).

### M6 — CSV/XLSX import memory + N+1
[app/Services/ContactImportService.php:26-36](../app/Services/ContactImportService.php#L26-L36) reads the whole file into `$rows` before `array_chunk` (chunking a fully-loaded array saves no peak memory), and `normalizePhone()` ([:122](../app/Services/ContactImportService.php#L122)) calls uncached `Setting::get('default_country_code')` **per row**. **Fix:** stream with a generator/`LazyCollection`, batch-insert, resolve the setting once per import.

### M7 — No CSP / HSTS
No `Content-Security-Policy` or `Strict-Transport-Security` anywhere; `deploy/nginx.conf:7-10` sets the lesser headers only. On pages that embed a live AT WebRTC token + CSRF token in the DOM. **Fix:** add a CSP (`script-src` self + pinned CDN hosts, nonce-based inline) and HSTS via middleware or nginx (depends on H5 for HTTPS detection).

### M8 — CDN scripts without SRI
[resources/views/layouts/app.blade.php:51](../resources/views/layouts/app.blade.php#L51) (chart.js, jsdelivr), [:54](../resources/views/layouts/app.blade.php#L54) (africastalking-client, unpkg) load third-party JS with no `integrity`/`crossorigin`. A compromised CDN release = script execution on every authenticated page. **Fix:** add SRI hashes or self-host.

### M9 — Reverb `allowed_origins = ['*']`
[config/reverb.php:85](../config/reverb.php#L85). Any origin can open a WebSocket to the broadcast server. **Fix:** restrict to the app host(s) via `REVERB_ALLOWED_ORIGINS`.

### M10 — `CampaignBatchDispatch` strands RUNNING on partial fan-out
A crash mid-fan-out can leave the campaign in RUNNING with a partial audience and no recovery path. **Fix:** set `$tries = 1` and a `failed()` handler that reconciles campaign status (mirror the message-job pattern).

### M11 — `MAIL_MAILER=log` default hides send failures
With `MAIL_MAILER=log` (or null SMTP creds) the email module "succeeds" while writing to the log file — campaigns show SENT, nothing is delivered. **Fix:** validate a real transport at boot when the email feature is enabled; document required MAIL_* vars; surface a settings warning.

---

## 🟢 Low (hardening / future-scale)

- **L1** — `Http::withOptions(['verify' => false])` on self-generated media probes: [CampaignController.php:98](../app/Http/Controllers/CampaignController.php#L98),207-208,330,447. Low exploitability (URL is self-generated) but an anti-pattern; gate behind config if it exists for staging self-signed certs.
- **L2** — Call-recording upload validates only `file|max:` and persists `getClientMimeType()` (browser-declared): [CallController.php:410-423](../app/Http/Controllers/CallController.php#L410-L423). Gated by the off-by-default recording flag; still add `mimes:webm,ogg,mp3,wav,m4a` + `getMimeType()`.
- **L3** — No `DB::transaction` around fan-out/import loops (`CampaignBatchDispatch:73-92`, `ContactController:266-292`) — partial state on mid-loop failure.
- **L4** — `SyncMessageTemplates::upsertAll()` ([:123-134](../app/Console/Commands/SyncMessageTemplates.php#L123-L134)) shares B1's soft-delete/unique bug on `message_templates` (lower risk — templates rarely deleted).
- **L5** — `PruneCallRecordings` ([:40-43](../app/Console/Commands/PruneCallRecordings.php#L40-L43)) daily-scans `call_logs` with no index on `recording_uploaded_at` (dormant until recording is enabled).
- **L6** — redis-vs-database queue inconsistency across `config/queue.php`, docs, and QueueDoctor tooling — pick one and reconcile (ties to H4).
- **L7** — A single worker process serializes `messages`/`default`/`imports`/`voice` — a big import can starve message sends. Run per-queue workers or weight them.
- **L8** — `AfricasTalkingVoiceService` REST calls ([:193](../app/Services/AfricasTalkingVoiceService.php#L193),[:247](../app/Services/AfricasTalkingVoiceService.php#L247)) have no `timeout()`/retry — a slow AT endpoint can hang a request/worker.
- **L9** — Horizon isn't in the documented deploy path and `horizon:snapshot` isn't scheduled (no queue-metrics history).
- **L10** — Observability: only `/up`. No error tracking (Sentry etc.), no queue-failure alerting.
- **L11** — `CallController::index` ([:71-117](../app/Http/Controllers/CallController.php#L71-L117)) pulls all of today's calls and computes answered/missed/status-breakdown in PHP, while the same method already does SQL aggregates for other metrics — inconsistent; push to SQL.
- **L12** — `Setting::get()` ([Setting.php:16-21](../app/Models/Setting.php#L16-L21)) is an uncached DB hit called repeatedly per request across voice/assigner/dashboard code. Memoize per-request or via app cache.
- **L13** — `DEPLOYMENT.md` staleness: `storage:link`, cron entry, and the "68 passing" test count are out of date (now 498 passing).

---

## Verified strong — do not "fix" these

The audits confirmed a lot is already done right; changing these would regress correctness:

- **Webhook auth**: Meta HMAC (`hash_hmac` + `hash_equals`, fails closed on empty `app_secret`) and AT secret-path segment (`hash_equals`, fails closed, logs route name not path).
- **Send idempotency (WhatsApp)**: `SendWhatsAppMessage` `$tries=1` + `refresh()`+status guard + `failed()` that never clobbers a resolved log — deliberate anti-duplicate design.
- **IDOR defense**: `CallController`/`VoicemailController`/`ConversationController` all layer per-resource "view_all or assigned-to-me" checks on top of route permissions; `claim` uses an atomic conditional UPDATE.
- **SSRF guard**: voicemail proxy allowlists scheme+host and disables redirects before fetching AT URLs.
- **Concurrency**: `CloudWebhookController::processStatuses` uses `DB::transaction` + `lockForUpdate`; counters use atomic `increment()`; terminal-state guard on AT callbacks.
- **Injection defense**: `VoiceXml` escapes with `ENT_XML1`; CSV export formula-injection guard; email-preview iframe sandboxed without `allow-scripts`.
- **Secrets**: `access_token`/`app_secret`/AT key encrypted at rest; Gemini key header-only; `.env` never committed; `composer audit` + `npm audit` both clean.
- **Feature flags** default OFF for unverified voice features; `.env.example` carries safe prod defaults.

---

## Recommended fix order

1. **B1, B2** — data loss + account takeover. Gate go-live.
2. **H4, H5** — silent feature outage + broken webhook auth in the exact prod topology.
3. **H1, H2, H3, H6** — email correctness/security; ship together since H6 is what makes H2/H3 fire.
4. **M1–M6** — the scale-dependent DB/queue issues, before onboarding large audiences.
5. **M7–M11, L*** — hardening pass.

Each Blocker/High fix should land with a regression test (the pattern already used across the suite).
