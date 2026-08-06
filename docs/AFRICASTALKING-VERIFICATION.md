# Africa's Talking Voice — Live Verification Checklist

The AT voice integration was built against Africa's Talking's **documentation**, not a
live account. Several pieces are therefore **speculative** and must be verified against
real traffic before relying on voice in production. This checklist walks each one, what
the code currently assumes, and how to confirm or fix it.

> Work top-to-bottom. Items 1–9 verify the **core** call path (must pass first). Item 11
> covers the **flag-gated call-flow features** (IVR/voicemail/queue/transfer/business
> hours) — enable and verify those only after the core path is green.

## Quick start — automated prerequisite pass

Before the manual steps, run the **Phase 0 gate runner** — it checks every
prerequisite that needs no live call and prints the ordered checklist:

```bash
php artisan voice:verify-live                                  # checks only
php artisan voice:verify-live --to=+2348012345678              # also places a real test outbound call
php artisan voice:verify-live --to=+2348012345678 --no-call    # checks, skip the test call
php artisan voice:verify-live --to=+2348012345678 --watch      # places the call, then follows it live
```

Exit code `0` = all hard checks pass (the checklist still applies); `1` = a
blocking prerequisite is missing (fix it before going live). Source:
`app/Console/Commands/VerifyVoiceLive.php`.

### Watch a live call and auto-validate it — `voice:watch`

Every AT callback is now recorded on the call (`call_logs.raw_event_log`, same
as Meta inbound), and `php artisan voice:watch` polls a call to its terminal
state, printing status transitions and then a **structured validation report**
(callbacks received · terminal state · contact/conversation linked · round-robin
assignment (inbound) · provider session id · connected/duration/cost · answered
in-browser). Exit `0` = all checks pass, `1` = something failed.

```bash
php artisan voice:watch --session=<atSessionId>   # follow the call verify-live just placed
php artisan voice:watch                           # newest call, or the next one created
php artisan voice:watch --timeout=300             # wait up to 5 min for a terminal state
```

For the **inbound** leg, start `php artisan voice:watch` in one terminal, then
dial the virtual number from your mobile — the watcher picks up the call as the
webhook creates it and validates the whole inbound path (assignment, agent
answered, duration, cost).

The report validates **persisted state only** — it cannot hear audio, so the
manual checks (two-way audio, both legs dropping on hangup) still apply.

## Prerequisites
- A live Africa's Talking account (not sandbox) with **Voice** enabled.
- A purchased **virtual/phone number** with a voice **callback URL** set to
  `https://<your-app>/webhooks/africastalking/voice/<AT_VOICE_WEBHOOK_SECRET>`
  (the secret path segment authenticates the callback — set `AT_VOICE_WEBHOOK_SECRET`
  in `.env` and use the same value in the URL).
- Credentials saved in the app: Settings → Africa's Talking card (username, API key,
  virtual number, per-minute rate).
- A publicly reachable app URL (use a tunnel like ngrok/cloudflared for local testing)
  and a browser tab open on any authenticated page (the softphone registers per page).
- Tail the logs while testing: `php artisan pail` (or `tail -f storage/logs/laravel.log`).

---

## 1. Webhook authentication — REBUILT (confirm the shape) ✅→⚠️
**Now:** the speculative HMAC scheme was replaced with an **unguessable secret path
segment** `/webhooks/africastalking/voice/{secret}` (`AT_VOICE_WEBHOOK_SECRET`),
compared in constant time and **fail-closed** unless the secret matches OR an IP
allowlist is enforced. The route also has `throttle:` + an optional IP-allowlist
middleware. Tests post the real **form-encoded** shape. See
`AfricasTalkingWebhookController::verifyWebhookAuth` + config `voice.at_webhook_secret`.

- [ ] Set `AT_VOICE_WEBHOOK_SECRET` and point AT's voice callback URL at
      `https://<app>/webhooks/africastalking/voice/<secret>` (the secret in the path).
- [ ] Trigger a real callback (place/receive a call). Confirm **no 401s** in the log —
      if you see 401s, the secret in AT's callback URL doesn't match the env value.
- [ ] (Optional) set `VOICE_DEBUG_LOG_WEBHOOKS=true` so every callback payload +
      headers are written verbatim to laravel.log — the easiest way to confirm the
      field names in §2. Turn it back off after verification (payloads contain PII).
- [ ] (Optional hardening) set `VOICE_WEBHOOK_IP_ALLOWLIST` to AT's published source
      ranges. Scrub `/webhooks/africastalking/voice/*` from access logs (the secret is in
      the path; the app's own middleware already avoids logging it).

## 2. Callback field names & the isActive branch
**Code assumes:** `sessionId`, `direction`, `status`, `isActive` (== '1' → call-control),
`callerNumber`, `destinationNumber`, `durationInSeconds`, `amount`, `currency`,
`hangupCause` (`AfricasTalkingWebhookController::handle`).

- [ ] Log a full call's callbacks and confirm each field name AT actually sends
      (casing matters — AT uses `callerNumber`/`destinationNumber` in docs, but verify).
- [ ] Confirm the **call-control vs notification** split: does AT POST with `isActive=1`
      when it wants Voice XML back, and `isActive=0` for status notifications? If AT uses
      a different signal, fix the branch at the top of `handle()`.

## 3. Status values → call-log states
**Code assumes:** `Ringing`, `InProgress`, `Completed`, `Failed`
(`handle()` match → ringing / connected / ended / failed).

- [ ] Confirm the exact `status` strings AT sends (case-sensitive). Map any extras
      (e.g. `Busy`, `NoAnswer`) to the right `CallLog::STATUS_*`. Unknown statuses
      currently fall through to `default => null` (no state change) — decide if that's ok.

## 4. Outbound call → WebRTC bridge
**Flow:** agent clicks call → `CallController::placeOutbound` → `placeCall()` REST
`POST voice.africastalking.com/call` → AT dials the customer → on answer AT requests the
voice callback → `AfricasTalkingWebhookController` returns `<Dial phoneNumbers="agent_{id}"/>`
→ AT bridges the customer to the agent's registered browser client.

- [ ] `placeCall()` returns a `sessionId` and the customer's phone rings. (This surface
      is the most solidly-built and unit-tested — expect it to work.)
- [ ] On answer, the agent's browser softphone receives the bridged leg and two-way
      audio flows. Confirm the `<Dial>` client name matches `agent_{userId}` and the
      capability token was minted for that same `clientName`.
- [ ] The `provider_session_id` on the call log matches AT's `sessionId` (needed so
      status webhooks find the row).

## 5. Inbound call → agent
**Flow:** customer dials the virtual number → AT hits the voice callback →
`handleInboundFirstEvent` creates contact/conversation/call-log and assigns an agent →
the call-control callback returns `<Dial phoneNumbers="agent_{assignedId}"/>`.

- [ ] A real inbound call rings the assigned agent's browser (softphone must be
      **registered before** the call — it registers on page load from the layout token).
- [ ] Round-robin assigns to an available (present, under-capacity) agent.
- [ ] `<Dial>` XML is well-formed and AT accepts it (watch for AT-side "invalid XML"
      errors in the AT dashboard call logs).

## 6. Server-side hangup — endpoint still unverified ⚠️
**Code assumes:** `endCall()` POSTs `voice.africastalking.com/queueStatus` with
`action=terminate`. `/queueStatus` is AT's *queued-call status* endpoint, not live-call
termination — very likely a no-op for an active bridged call. **Since built:** `endCall`
now **throws** on failure and hangup runs through the retried `TerminateProviderCall`
job, so teardown is reliable *if the endpoint is right* — but the endpoint itself is
still unconfirmed.

- [ ] Hang up from the browser and confirm BOTH legs drop. The browser also calls the
      SDK `hangup()`, which usually ends the bridge, so this may *appear* to work — verify
      the **customer leg** actually drops (call your own mobile, hang up in the browser,
      confirm your mobile call ends).
- [ ] If the customer leg stays up, replace the `/queueStatus` call in
      `AfricasTalkingVoiceService::endCall` with AT's real live-call termination mechanism.
      (The retry job + throwing endCall are already in place, so only the endpoint needs
      correcting.)

## 7. Cost / billing
**Code assumes:** a flat estimate `durationSeconds * rate_per_minute_kobo / 60`
(`finalizeCall`), discarding AT's reported `amount`/`currency`.

- [ ] Confirm AT's `Completed` callback includes `amount`/`currency`. If so, persist the
      **actual** amount and fall back to the estimate only when absent (destination/tariff
      rates vary; the flat estimate will drift).

## 8. Hold & DTMF (newly wired — needs a live check)
The in-call card wires **Hold** (`hold()`/`unhold()`) and the **Keypad** (`dtmf()`) to the
`africastalking-client@1.0.7` SDK. These were verified against the SDK's *documented*
method surface only.

- [ ] On a live call, press **Hold** → confirm the customer hears hold/silence and audio
      resumes on unhold. If the SDK method differs, adjust `bqVoiceClient.hold/unhold`.
- [ ] Open the **Keypad** and press digits → confirm DTMF tones reach an IVR on the other
      end. If `dtmf()` isn't the right method/signature, adjust `bqVoiceClient.dtmf`.
- [ ] Confirm **Mute** and **Hang up (Drop)** still behave after the reskin.

## 9. Softphone registration & token
- [ ] Capability token mints from `webrtc.africastalking.com/capability-token/request`
      (real token, not the local stub) and the client reaches the `ready` event
      (no red "Voice offline" pill in the header).
- [ ] The token refreshes correctly on a long-lived tab (current code re-reads the
      layout meta tag on reconnect — if AT tokens expire faster than expected, add a
      `GET /voice/token` refresh endpoint).

## 10. Known non-blocking cleanups
- [x] ~~Add `throttle:` to the webhook route~~ — done (config `voice.webhook_rate_limit`).
- [x] ~~`provider_session_id` unique index~~ — done (migration `..._make_provider_session_id_unique`).
- [x] ~~The AT SDK loads from `unpkg.com` with no SRI~~ — done: the SDK is self-hosted
      (`vendor/africastalking-1.0.7.js`), loaded same-origin from the layout, and the
      reconnect path force-reloads the same self-hosted file (no external CDN).
- [x] ~~Packet-loss telemetry sums cumulative counters (over-counts)~~ — done:
      `call-stats-collector.js` now derives loss from per-interval deltas.
- [ ] TURN relay is wired for the Meta path and passed best-effort to the AT SDK
      constructor (v1.0.7 may ignore it) — on a restrictive-NAT call, confirm the relay
      actually engages for AT (`VOICE_TURN_*`).

## 11. Call-flow features (built, flag-gated OFF) — verify each before enabling ⚠️
These ride on AT Voice-XML actions built to spec but **never run on a live account**.
Enable **one flag at a time** and verify. Full enable/verify steps are in
`docs/CALL-FLOW.md`; the live-behaviour risks to confirm here:

- [ ] **IVR** (`VOICE_IVR_ENABLED`): does AT execute `<GetDigits>` and POST the pressed
      keys back as `dtmfDigits` to the `callbackUrl`? Confirm the callback field name and
      that single-digit collection works. (`CallFlowRouter::menuXml` / `digitSelectionXml`.)
- [ ] **Voicemail** (`VOICE_VOICEMAIL_ENABLED`): does `<Record>` post a `recordingUrl`
      (+ `durationInSeconds`) to the callback? Confirm those field names — the voicemail
      row is created from them (`storeVoicemail`). Confirm the recording is publicly
      playable from `/voicemails`, or mirror it to the private disk if AT URLs expire.
- [ ] **Business hours** (`VOICE_BUSINESS_HOURS_ENABLED`): closed-hours call routes to the
      closed message / voicemail. Check the timezone (`VOICE_BUSINESS_TZ`).
- [ ] **Queue** (`VOICE_QUEUE_ENABLED`): does `<Enqueue name=… holdMusic=…>` hold the
      caller with music? **Dequeue/agent-pull is NOT built** — decide the pull mechanism
      (agent dials in → `<Dequeue>` XML) once Enqueue is confirmed.
- [ ] **Blind transfer** (`VOICE_TRANSFER_ENABLED`): after the agent transfers and their
      leg drops, **does AT re-request call-control?** The whole mechanism depends on it —
      if AT does NOT re-request, transfer needs a different approach (e.g. `<Redirect>`
      issued before the agent leg drops). Attended transfer is not built.

---

### Definition of done
A test call in **each** direction (inbound + outbound) that: rings the right agent's
browser, connects two-way audio, shows correct status + timer, supports mute/hold/keypad,
and **fully terminates both legs** on hang up — with **no** auth 401s in the log. Then,
per feature you intend to use, the §11 checks pass with its flag on.
