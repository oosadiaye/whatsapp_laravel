# Africa's Talking Voice — Live Verification Checklist

The AT voice integration was built against Africa's Talking's **documentation**, not a
live account. Several pieces are therefore **speculative** and must be verified against
real traffic before relying on voice in production. This checklist walks each one, what
the code currently assumes, and how to confirm or fix it.

> Work top-to-bottom. Item 1 (webhook auth) is the most likely to be wrong and blocks
> *everything* — if callbacks 401, no call ever connects.

## Prerequisites
- A live Africa's Talking account (not sandbox) with **Voice** enabled.
- A purchased **virtual/phone number** with a voice **callback URL** set to
  `https://<your-app>/webhooks/africastalking/voice`.
- Credentials saved in the app: Settings → Africa's Talking card (username, API key,
  virtual number, per-minute rate).
- A publicly reachable app URL (use a tunnel like ngrok/cloudflared for local testing)
  and a browser tab open on any authenticated page (the softphone registers per page).
- Tail the logs while testing: `php artisan pail` (or `tail -f storage/logs/laravel.log`).

---

## 1. Webhook authentication — MOST LIKELY WRONG ⚠️
**Code assumes:** an HMAC-SHA256 of the raw body against the API key, in header
`X-Africastalking-Signature` (`AfricasTalkingWebhookController::verifySignature`). The
docblock itself says *"verify at deploy time."* AT voice callbacks are typically
**form-encoded and unsigned** — secured by **IP allowlist**, not a body signature.

- [ ] Trigger any real callback (place or receive a call). Check the log for
      `invalid signature` 401s. If **every** real callback 401s, the scheme is wrong.
- [ ] Inspect a raw incoming callback (log `$request->headers` + `$request->getContent()`):
      is there any signature header at all? Is the body `application/x-www-form-urlencoded`
      (not JSON)?
- [ ] **If unsigned (expected):** replace the HMAC check with (a) an unguessable secret
      path segment on the route and/or (b) an AT source-IP allowlist, and add
      `throttle:` middleware (the route currently has none). Update
      `AfricasTalkingWebhookTest` to sign/post the **form-encoded** shape it really uses
      (the tests currently self-sign JSON, so they prove consistency, not compatibility).
- [ ] **If signed:** confirm the exact header name and the HMAC input (raw body? which
      secret?) and correct `verifySignature`.

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

## 6. Server-side hangup — LIKELY A NO-OP ⚠️
**Code assumes:** `endCall()` POSTs `voice.africastalking.com/queueStatus` with
`action=terminate`. `/queueStatus` is AT's *queued-call status* endpoint, not live-call
termination — this is very likely a no-op for an active bridged call.

- [ ] Hang up from the browser and confirm BOTH legs drop. The browser also calls the
      SDK `hangup()`, which usually ends the bridge, so this may *appear* to work — verify
      the **customer leg** actually drops (call your own mobile, hang up in the browser,
      confirm your mobile call ends).
- [ ] If the customer leg stays up, replace the `/queueStatus` call with AT's real
      live-call termination mechanism (typically returning hangup Voice XML on the next
      control callback, or the voice-actions API). Stop treating a swallowed error as
      success in `AfricasTalkingVoiceService::endCall`.

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

## 10. Known non-blocking cleanups (do while you're in here)
- [ ] The AT SDK loads from `unpkg.com` with no SRI — self-host + pin + SRI, and add the
      origin to CSP.
- [ ] Add `throttle:` to the webhook route (item 1).
- [ ] `provider_session_id` is indexed but not **unique** — add a unique index to close
      the duplicate-inbound-row race.
- [ ] Packet-loss telemetry sums cumulative counters (over-counts) — use last-sample or
      per-interval deltas in `call-stats-collector.js`.

---

### Definition of done
A test call in **each** direction (inbound + outbound) that: rings the right agent's
browser, connects two-way audio, shows correct status + timer, supports mute/hold/keypad,
and **fully terminates both legs** on hang up — with **no** `invalid signature` 401s in
the log.
