# Inbound call-flow engine — operator guide

Turns an inbound Africa's Talking call into the right experience: **business
hours → IVR menu → destination** (ring an agent, hold in a queue, or take a
voicemail), plus **blind transfer** of a live call.

Everything here is **off by default** and built against AT's documented Voice
API — it has **not** run on a live account. Enable one flag at a time and verify
each against `docs/AFRICASTALKING-VERIFICATION.md` §11 before relying on it.

> Prerequisite: the **core** voice path (place/receive a call, two-way audio,
> hangup) must already work — see the rest of the verification checklist. These
> features shape *what happens* on an inbound call; they don't make voice work.

---

## How it routes

```
inbound call → AT posts call-control → CallFlowRouter::entryXml
  business_hours_enabled & closed? → closed message → (voicemail if on)
  ivr_enabled?                     → <GetDigits> menu → caller presses a key →
                                       CallFlowRouter::digitSelectionXml →
                                         agent | queue | voicemail
  otherwise                        → dial assigned agent
                                       (else queue if on, else voicemail if on,
                                        else "agents busy")
```

`App\Services\CallFlowRouter` builds the Voice XML (via `App\Support\VoiceXml`);
`AfricasTalkingWebhookController` delegates inbound call-control + digit callbacks
to it and stores `<Record>` results as voicemails.

---

## 0. Authenticate the webhook first (required)

AT voice callbacks are unsigned, so the callback URL carries a secret:

```env
AT_VOICE_WEBHOOK_SECRET=some-long-random-string
```

Point AT's voice callback at `https://<app>/webhooks/africastalking/voice/<secret>`.
Auth **fails closed** — with no secret set (and no IP allowlist) the webhook
rejects. Optionally add `VOICE_WEBHOOK_IP_ALLOWLIST` (AT's ranges, IPs/CIDR).

---

## 1. Business hours

```env
VOICE_BUSINESS_HOURS_ENABLED=true
VOICE_BUSINESS_TZ=Africa/Lagos
```

Weekly schedule + closed message live in `config/voice.php` → `business_hours`
(HH:MM windows per day; a `null` day is closed all day). Closed → the closed
message, then voicemail if voicemail is enabled.

## 2. IVR menu

```env
VOICE_IVR_ENABLED=true
```

Prompt + options in `config/voice.php` → `ivr`. Each option maps a pressed digit
to a destination:

```php
'options' => [
    '1' => ['type' => 'agent',     'label' => 'Sales'],
    '2' => ['type' => 'queue',     'label' => 'Support', 'queue' => 'support'],
    '3' => ['type' => 'voicemail', 'label' => 'Voicemail'],
],
```

Invalid keys re-play the menu. Verify AT posts the pressed key back as
`dtmfDigits` (checklist §11).

## 3. Voicemail

```env
VOICE_VOICEMAIL_ENABLED=true
```

Greeting + max length in `config/voice.php` → `voicemail`. Messages land in the
**/voicemails** inbox (play + mark-heard), gated by conversation visibility. The
row is created from AT's `recordingUrl` callback — if AT's recording URLs expire,
mirror them to the private disk (a follow-up, mirroring call-recording storage).

## 4. Call queue

```env
VOICE_QUEUE_ENABLED=true
VOICE_QUEUE_HOLD_MUSIC=https://<public>/hold.mp3
```

Callers with no free agent (or who pick a queue option) are `<Enqueue>`d with
hold music. **Heads-up:** only the *enqueue* (caller waits) half is built — the
**dequeue / agent-pull** mechanism is not. Decide how agents pull from the queue
once Enqueue is confirmed live.

## 5. Blind transfer

```env
VOICE_TRANSFER_ENABLED=true
```

A **Transfer** control appears on the in-call softphone card. The agent enters a
number; the server records the target, the agent's leg drops, and the next AT
call-control request Dials the customer to the target. Internal agent-to-agent
transfer is supported by the API (`target_type=agent`) and reassigns + rings the
target. **Critical to verify (checklist §11):** that AT **re-requests
call-control after the agent leg drops** — the whole mechanism depends on it.
Attended (consult-first) transfer is not built.

---

## Verify

For each feature you turn on, walk `docs/AFRICASTALKING-VERIFICATION.md` §11.
The high-risk unknowns are the AT callback field names (`dtmfDigits`,
`recordingUrl`) and whether AT re-requests call-control after a transfer.

## Not built (deliberately, pending live verification)
- Queue **dequeue / agent-pull**.
- **Attended** transfer (consult, then complete).
- An IVR/business-hours **builder UI** (config-file driven for now).
- Mirroring voicemail/AT recordings to the private disk.
