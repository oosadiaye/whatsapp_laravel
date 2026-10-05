# AI Automation via OpenRouter — Implementation Plan

**Written for:** an engineer implementing this on the `ai-automate` branch (Laravel 12 + Livewire v4, BlastIQ).
**Status:** plan / not started. **Branch:** `ai-automate` (off `main` @ prod-current).
**Decisions locked (2026-10-06):** autonomy = **draft & human-approve** · channels = **WhatsApp + Email + Calls** · provider = **add OpenRouter alongside Gemini (selectable)**.

---

## 1. Objective & guardrails

Add an AI layer that **drafts** replies, content, and follow-ups across WhatsApp, Email and Calls, powered by **OpenRouter** (selectable model) with the existing **Gemini** kept as an option. **Every AI output is a draft a human reviews and sends — nothing is auto-sent, auto-replied, or auto-dialed.** The human click is the safety boundary, which removes the need for autonomous-send guardrails, kill-switches on AI sending, etc.

Non-negotiables (match the app's conventions):
- **Flag-gated OFF by default.** No feature does anything until its flag is on *and* a provider key is configured.
- **Graceful degradation.** No key / provider error → return `null` and the UI simply shows no suggestion (exactly how `AiEmailDraftService::draft()` behaves today).
- **Settings-driven, encrypted.** Keys/model/provider live in the Settings page (DB, `Setting::setEncrypted`), env as fallback — mirroring `App\Support\GeminiConfig`.
- **Inbound customer content is untrusted.** Treat it as data in the prompt, never as instructions (prompt-injection safety); sanitize/escape drafts before display.
- **Keep the suite green** at every step; each feature ships with tests.

### Out of scope (note for the reader)
- **A true AI voice bot** (AI answering/talking on a live call) — OpenRouter is text-only; that needs TTS + STT + realtime media and is a separate future phase. "Calls" here means AI *summaries, live-assist text, and post-call follow-up drafts*.
- **Autonomous sending** of any kind (explicitly deferred per the autonomy decision).

---

## 2. What already exists (build on it, don't duplicate)

| Thing | File | Reuse as |
|---|---|---|
| Gemini key resolver (settings→env) | `app/Support/GeminiConfig.php` | template for `AiConfig` |
| AI email draft (HTTP → Gemini) | `app/Services/AiEmailDraftService.php` | refactor to call the new `AiClient` |
| Call transcription + summary | `app/Services/GeminiTranscriptionService.php` | keep STT on Gemini; route the *summary* LLM step through `AiClient` |
| Mailbox compose | `app/Livewire/Mailbox/Concerns/WithCompose.php`, `app/Livewire/Mailbox/Inbox.php` | host "AI draft reply" |
| Call insights UI | `app/Livewire/CallInsightsPanel.php`, `app/Livewire/CallWrapUp.php` | host follow-up drafts |
| WhatsApp conversation UI | `app/Livewire/ConversationThread.php`, `resources/views/conversations/show.blade.php` | host "Suggest reply" |
| Settings (keys, encrypted) | `app/Http/Controllers/SettingsController.php`, `resources/views/settings/index.blade.php`, `app/Models/Setting.php` | add the AI provider panel |
| Audit log | `app/Services/AuditService.php`, `app/Models/AuditLog.php` | record AI usage/cost |
| Campaign composers | `CampaignController` + `campaigns/_form`; `EmailCampaignController` + `email-campaigns/_form`; `EmailSequenceController` | host "Generate with AI" |

No OpenRouter code exists yet (confirmed).

---

## 3. Phase 0 — AI provider foundation *(prerequisite for everything)*

**Goal:** one provider-agnostic way to call an LLM, selectable between OpenRouter and Gemini, configured from Settings.

**Create:**
- `config/ai.php` — `provider` (default from env), model defaults per tier (`draft`, `classify`), `timeout`, `max_output_tokens`, per-feature flags (`whatsapp_suggest`, `email_draft`, `campaign_content`, `call_followup`, all default `false`), and cost caps.
- `app/Support/AiConfig.php` — resolves `provider()`, `key()`, `model()` from `Setting` (encrypted) → env, mirroring `GeminiConfig`.
- `app/Services/Ai/AiClient.php` (interface): `chat(array $messages, array $opts = []): ?AiResult` where `$messages` is OpenAI-style `[{role, content}]`. Returns `null` on any failure (graceful).
- `app/Services/Ai/AiResult.php` — DTO: `text`, `model`, `promptTokens`, `completionTokens`, `costUsd` (nullable).
- `app/Services/Ai/OpenRouterClient.php` — `POST https://openrouter.ai/api/v1/chat/completions`, `Authorization: Bearer <key>`, body `{model, messages, max_tokens, temperature}`, plus the recommended `HTTP-Referer`/`X-Title` headers. Parse `choices[0].message.content` + `usage`. Timeout + try/catch → `null` on failure (log a warning, never throw to the caller).
- `app/Services/Ai/GeminiClient.php` — adapts the existing Gemini `generateContent` call behind the same `AiClient` contract (so `AiEmailDraftService` logic is reused, not forked).
- `app/Services/Ai/AiProviderFactory.php` — returns the right `AiClient` by `AiConfig::provider()`. Bind `AiClient` in a service provider to the factory result.

**Settings UI (superadmin AI panel in `settings/index.blade.php` + `SettingsController`):**
- Provider dropdown (`gemini` | `openrouter`), encrypted API-key field, **model selector** (curated list, or live-fetched from OpenRouter `GET /models` with a cached fallback), per-feature toggles, and a **"Test connection"** button (sends a 1-token ping, shows ok/fail). Validate inputs; store via `Setting::setEncrypted`. Gate the panel on a superadmin permission.

**Refactor:** `AiEmailDraftService::draft()` → call `app(AiClient::class)->chat(...)` instead of hitting Gemini directly. Its public behavior (returns `?string`) is unchanged, so its callers/tests keep working.

**Tests (Phase 0 acceptance):**
- `OpenRouterClient` with `Http::fake()` — happy path parses text+usage; non-2xx and exceptions → `null`.
- `AiConfig` precedence (settings over env; no key → `null`).
- `AiProviderFactory` returns the configured client.
- Settings page: provider/model/key persist (encrypted), Test-connection endpoint, superadmin-gated.
- `AiEmailDraftService` still returns a draft through the new path (existing tests pass).

**Done when:** an operator can pick OpenRouter + a model + key in Settings, click Test-connection → success, and `AiEmailDraftService` produces a draft through OpenRouter.

---

## 4. Phase 1 — WhatsApp message AI (draft/suggest) · *depends on Phase 0*

- **Suggested reply (inbound):** add a **"Suggest reply"** action to the conversation thread (`ConversationThread` / `conversations/show.blade.php`). Builds a prompt from recent thread messages + contact fields → `AiClient->chat()` → fills the reply box as an **editable draft** the agent sends via the existing send path. Never auto-sends. Flag `whatsapp_suggest`, permission `conversations.reply`.
- **Campaign/template content:** "Generate with AI" in the campaign composer (`campaigns/_form`) → drafts a message/template body from a short brief; operator edits + submits through the existing `StoreCampaignRequest` flow (so template rules/validation still apply).
- **(Optional) Intent classification:** a cheap-model call to tag inbound messages (interested / complaint / opt-out) for prioritization — stored on the conversation; purely advisory.
- **Guardrails:** respect the 24h window + opt-out/suppression exactly as today (AI only drafts into the same send path that already enforces these); inbound text treated as untrusted in the prompt.
- **Tests:** suggest-reply action (`Http::fake` AI) returns an editable draft and sends nothing; content generation; permission + flag gating; no-key → no suggestion.

---

## 5. Phase 2 — Email AI (draft/suggest) · *depends on Phase 0 · easiest first slice*

- **Mailbox reply drafting:** "AI draft reply" in the mailbox (`WithCompose` / `Mailbox\Inbox`) → drafts a reply-as-self from the thread → editable in the composer → agent sends via the existing `SendUserEmail` path. Reuse `AiEmailDraftService` (now provider-agnostic).
- **Campaign & sequence content:** "Generate with AI" in `email-campaigns/_form` and the sequence-step editor → subject + body drafts → operator reviews/saves through existing validation.
- **Guardrails:** suppression/unsubscribe unaffected (AI only drafts; the send path enforces them); sanitize/escape AI output (it renders in HTML email previews — keep the existing sandbox-iframe preview).
- **Tests:** draft reply, campaign/sequence content, provider switch (gemini↔openrouter) both produce drafts, flag/permission gating.

> Recommended **first vertical slice** after Phase 0: email reply drafting — the service already exists, so it proves the provider abstraction + UX with the least new surface.

---

## 6. Phase 3 — Calls AI (assist + follow-up) · *depends on Phase 0*

- **Post-call follow-up drafts:** after a call, use the transcript/summary (`GeminiTranscriptionService`) to draft a follow-up **WhatsApp or email** (`CallWrapUp` / `CallInsightsPanel`) → agent reviews/sends through the existing channels. This is the highest-value, lowest-risk call automation.
- **Summary model selectable:** route the *summarization* LLM step through `AiClient` so the model is configurable (keep **STT/transcription on Gemini** — OpenRouter can't do audio).
- **(Stretch) Live-assist talking points:** during a connected call, surface AI-suggested talking points from the running context. Lower priority — realtime/partial-transcript complexity; design separately.
- **Tests:** follow-up draft generation from a transcript (`Http::fake` AI), summary via `AiClient`, gating.

---

## 7. Cross-cutting

- **Cost control:** per-feature flags; `max_output_tokens`; per-user/org rate limit on AI calls; a cheap model for classify vs a better model for drafting; cap + monitor spend.
- **Observability/audit:** log every AI call (feature, provider, model, tokens, est. cost, user) via `AuditService`; a small "AI usage" view for admins.
- **Privacy:** customer messages/emails/transcripts go to a 3rd party (OpenRouter). Make each feature **opt-in**, disclose it, and document which data leaves the system. Consider a setting to exclude PII fields.
- **Prompt-injection:** inbound content is data, never instructions; a fixed system prompt; strip/ureutralize attempts; never let a draft trigger an action on its own (it can't — human sends).
- **Resilience:** timeouts, retries only on transient network errors (not on a model response), `null` on failure.

---

## 8. Dependency graph & suggested order

```
Phase 0 (foundation) ──┬── Phase 2 Email      (do FIRST after P0 — smallest)
                       ├── Phase 1 WhatsApp
                       └── Phase 3 Calls
```
Phases 1–3 are independent once Phase 0 lands, so they can be built in any order or in parallel. Each is one focused PR.

## 9. Acceptance / verification per phase
- Unit + feature tests green (80%+), suite stays green.
- Each feature: works with a key, degrades to "no suggestion" without one, respects its flag + permission, and **sends nothing without a human click** (assert no send/dispatch in the draft tests).
- A manual live smoke per feature once a real OpenRouter key is set.

## 10. Open decisions to confirm during build
- Curated model list vs live `GET /models` (start curated + cached).
- Default models per tier (e.g. a small model for classify, a mid model for drafting) — pick on cost/quality once testing.
- Whether campaign/sequence content generation needs brand-voice inputs (could reuse a stored tone/brief).
