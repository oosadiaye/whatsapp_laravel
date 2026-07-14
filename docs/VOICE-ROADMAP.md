# Voice / Telephony Build-Order Roadmap

Turning the app's Africa's Talking softphone into a "3CX for WhatsApp" — in
dependency order. Each phase is one (or a few) PR-sized steps. Every voice
feature rides the **Africa's Talking Voice API** (Voice XML actions:
`Say` / `Play` / `GetDigits` / `Dial` / `Record` / `Enqueue` / `Dequeue` /
`Redirect` / `Reject`) + the browser SDK (`hold`/`dtmf`/`mute` already wired) +
**Laravel Reverb** for realtime + **Livewire** for UI.

## The gate
> **Nothing in Phase 1–4 ships to production before Phase 0 is green.** Every
> feature here assumes the AT integration actually works end-to-end. Today it is
> *unverified* (hangup mechanism + callback field names are doc-guesses — see
> `docs/AFRICASTALKING-VERIFICATION.md`). Building IVR/recording/transfer on an
> unproven base means debugging two layers at once.

## Build status (2026-07 — code done, live-unverified)
Most of Phase 1–4 is now **built to spec and flag-gated OFF**, pending live
verification (checklist §11 + `docs/CALL-FLOW.md`):
- **P0 hardening:** webhook auth rebuilt (secret path segment, fail-closed) +
  throttle + IP allowlist; `provider_session_id` now unique; reliable hangup via
  a retried job (endpoint still unverified); TURN wired.
- **P1 recording:** done — browser records → Gemini transcript/summary (see
  `docs/CALL-WORKSPACE.md`), with retention pruning.
- **P2 transfer:** **blind** transfer built (flag-gated). Attended not built.
- **P3 IVR + voicemail + business hours:** built (flag-gated) via `CallFlowRouter`.
- **P4 wallboard:** basic wallboard built (`/wallboard`).
- **Queue:** `<Enqueue>` built; dequeue/agent-pull not built.

The remaining work is **live verification** (flip each flag, walk the checklist)
plus the explicitly-unbuilt pieces above — not net-new construction.

## Dependency graph
```
                 ┌─────────────────────────────────────────┐
   Phase 0  ─────▶  P1 Recording   ─┐                        │
   (verify) ─────▶  P2 Transfer    ─┼─▶ (parallel after P0)  │
            ─────▶  P4a Wallboard  ─┘                        │
            ─────▶  P3 IVR ──────────▶ P4b Wallboard (queue) ┘
                        ▲
                   needs P1's Record action for the "voicemail" menu option
```
- **P0 gates everything.**
- **P1, P2, P4a** are largely parallel after P0 (mostly different files).
- **P3 (IVR)** can start its menu engine after P0, but its *voicemail* destination reuses P1's recording plumbing.
- **P4b** (queue metrics on the wallboard) needs P3's queue concept.

## Cross-cutting (applies to several phases)
- **Feature flags** — ship each feature dark behind `config/voice.php` (mirror `meta_calling_enabled`): `recording_enabled`, `transfer_enabled`, `ivr_enabled`. Flip on per-phase; instant rollback.
- **Compliance** — call recording needs a consent notice ("this call may be recorded") via an IVR `Say` prompt + a documented retention policy. Non-negotiable before recording ships.
- **Storage** — recordings + voicemail on the **private** disk, permission-gated stream (mirror inbound-media handling), with a retention/delete job.
- **SDK hardening** — self-host `africastalking-client` + pin + SRI (currently unpkg CDN, no SRI). Do during P0.
- **Config surface** — IVR builder + business-hours live in Settings (the page was just restyled); grow it deliberately.
- **Agent grouping** — IVR skill-routing wants departments/skills. Start with "ring all" / round-robin (exists) and add grouping only when needed.

## Workflow (per PR)
Branch off `main` → TDD (`php artisan test`, run via `serve.bat`/`C:\xamppp` PHP) → green suite → `npm run build` if JS/CSS touched → PR → merge. Each feature behind its flag.

---

## Phase 0 — Verify Africa's Talking live (GATE)
**Goal:** voice works end-to-end both directions with zero `invalid signature` 401s, correct hangup of both legs, and confirmed callback fields.
**Prerequisite:** a **live AT account** + a virtual number with the voice callback URL set (this phase cannot start without it).
**Scope (fixes the verification will almost certainly surface):**
- Replace the speculative HMAC webhook check with AT's real control — most likely **IP allowlist + unguessable secret path segment + `throttle:`** (AT voice callbacks are unsigned form-encoded). `AfricasTalkingWebhookController::verifySignature`.
- Fix server-side hangup: `/queueStatus` is likely a no-op — use AT's real live-call termination. `AfricasTalkingVoiceService::endCall`.
- Confirm callback field names + status values; correct the `handle()` match + `handleInboundFirstEvent`.
- Add a **unique index on `call_logs.provider_session_id`** (currently only indexed) to close the duplicate-inbound-row race.
- Verify the newly-wired **Hold/DTMF** on a real call; self-host the SDK + SRI.
- **Confirm the Voice XML actions later phases depend on are available on your AT plan/account:** `Record` (P1 recording + P3 voicemail), `GetDigits` (P3 IVR), `Enqueue`/`Dequeue` (P3 queue / P4b), `Redirect`/`Dial` (P2 transfer). If any is unsupported, that phase needs a rethink — find out now, not mid-build.
**Tests:** rewrite `AfricasTalkingWebhookTest` to post the **real** (form-encoded) shape once known; the current tests self-sign JSON and only prove internal consistency.
**Risk:** highest-uncertainty phase; scope depends on what live traffic reveals. **Effort:** 1–3 days once the account exists.
**Exit:** the "Definition of done" in `docs/AFRICASTALKING-VERIFICATION.md`.

## Phase 1 — Call recording  ·  depends on P0  ·  effort: M
**Goal:** record calls, store them privately, play/download from `/calls`.
**AT primitives:** `record="true"` on the `<Dial>` (or a `Record` action) → AT returns `recordingUrl` on the `Completed` callback.
**Backend:** capture `recordingUrl` in `AfricasTalkingWebhookController::finalizeCall`; **download + store** on the private disk (don't hotlink AT's URL — retention/privacy); optional consent `Say` prompt on connect.
**Data model:** migration on `call_logs` → `recording_path` (private disk key), `recording_duration_seconds`, `recording_available` (bool). `CallLog::recordingUrl()` accessor mirroring `ConversationMessage::displayMediaUrl`.
**Frontend:** a play/download control in the `/calls` row (page already built) + a permission-gated stream route (mirror `ConversationController::downloadMedia`).
**Config/compliance:** `voice.recording_enabled` flag + retention policy + consent notice.
**Tests:** webhook with `recordingUrl` → `recording_path` set; stream route is permission-gated (view_all / assigned only); flag off → no recording.
**Risk:** legal (consent), storage growth (retention job). **Rollback:** flag off.

## Phase 2 — Call transfer (blind first)  ·  depends on P0  ·  effort: M (2a) / M+ (2b)
**Goal:** an agent hands a live call to another agent or an external number.
**AT primitives:** `Redirect` to a Voice XML that `Dial`s the target client `agent_{id}` (or a PSTN number).
**2a — Blind transfer:**
- Backend: `POST /calls/{call}/transfer` → `CallController::transfer` → `AfricasTalkingVoiceService` builds the redirect/dial; `authorizeCallAccess` guard.
- UI: a **Transfer** control on the in-call card (`resources/views/livewire/partials/call-card.blade.php`) → picker (active agents from presence / free-form number) → POST. NOTE: the AT SDK has **no** transfer method (`call/answer/hangup/dtmf/mute/hold` only), so transfer is **server-side** — the factory method just POSTs to `/calls/{call}/transfer`, and the server tells AT to `Redirect` the live leg. The agent's own leg then drops (blind).
- Realtime: broadcast to the target agent (new `CallRinging`-style event) so their softphone rings.
- Data: `call_logs` → `transferred_to_user_id`, `transferred_at`, `transfer_type`.
**2b — Attended transfer (later):** consult the target first (hold customer → call agent → merge/complete). More state; ship after 2a proves out.
**Tests:** transfer endpoint auth + state transition; AT payload shape (`Http::fake`); target-agent notification dispatched.
**Risk:** attended transfer state machine; AT redirect semantics (verify in P0). **Rollback:** `voice.transfer_enabled` flag hides the control + guards the route.

## Phase 3 — IVR / auto-attendant  ·  depends on P0 (voicemail option needs P1)  ·  effort: L
**Goal:** inbound callers hear a menu ("Press 1 for Sales…") and route accordingly, instead of an immediate ring.
**AT primitives:** `Say`/`Play` + `GetDigits` on the first inbound callback → on the digit callback, route via `Dial` (agent group / round-robin), `Record` (voicemail), or `Enqueue` (queue).
**3a — Menu engine (single level):**
- Backend: `AfricasTalkingWebhookController::handleInboundFirstEvent` returns IVR XML when IVR is enabled; a new digit-callback branch maps option → destination.
- Data: `ivr_menus` / `ivr_options` (option digit → destination type + target), or a JSON config setting to start.
**3b — Builder UI:** a menu editor in Settings (prompt text/audio, options → destination). Start minimal: one menu, destinations = "ring all agents" / "voicemail" / "specific agent".
**3c — Business-hours routing:** open → IVR/queue, closed → voicemail/after-hours message (a schedule config + a time branch).
**Tests:** inbound webhook returns the expected IVR XML (assert the XML); digit `1` routes to the mapped destination; closed-hours → voicemail branch.
**Risk:** multi-step call-flow state; XML correctness (AT rejects malformed XML — verify in P0); depends on P1 for the voicemail destination. **Rollback:** `voice.ivr_enabled` off → falls back to today's direct-assign behavior.

## Phase 4 — Live wallboard  ·  4a after P0 (parallel) · 4b after P3  ·  effort: M (4a) / L (4b)
**Goal:** a real-time operations board — who's on a call, who's waiting, today's stats.
**Leverages what exists:** `CallRinging`/`CallClaimed`/`CallTerminated` already broadcast over **Reverb**; presence (`PresenceToggle`, `TeamLoad`) and MOS/durations on `call_logs` are already collected.
**4a — Basic (buildable right after P0):**
- Livewire `Wallboard` component (pattern of `RealtimePulse`/`TeamLoad`): live calls (ringing/connected), agent grid (available/on-call/away from presence), today tiles (calls handled, avg talk, answer rate, MOS) — reuse the `/calls` trend-stat approach.
- Page at `/wallboard` (or extend `/team`); Reverb-subscribed + `wire:poll` fallback; visibility-scoped + `permission:team.view`.
**4b — Queue metrics (after P3):** calls-waiting depth, longest wait, SLA % — needs the queue concept from IVR/Enqueue.
**Tests:** Livewire component renders live counts; visibility scoping; permission gate.
**Risk:** low (mostly aggregation + existing realtime). **Rollback:** it's a new read-only page — remove the route.

---

## Suggested build order & parallelism
1. **P0** (blocking, needs live account) — verify + harden.
2. Then in parallel: **P1 (recording)**, **P2a (blind transfer)**, **P4a (basic wallboard)** — mostly disjoint files.
3. **P3 (IVR)** — the big one; start the menu engine alongside step 2, wire the voicemail option after P1 lands.
4. **P2b (attended transfer)** and **P4b (queue metrics)** last — they build on 2a and P3.

## Not on this roadmap (deliberately)
Internal extension-to-extension dialing, SIP trunk management, fax, hot-desking, physical desk phones, video (WhatsApp owns that channel) — no fit for a browser-first WhatsApp tool. See the strategic notes: these add PBX surface without user value here.
