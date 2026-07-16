<?php

declare(strict_types=1);

/**
 * Voice / WebRTC configuration.
 *
 * MOS calibration constants and STUN/TURN server lists used to live as
 * hard-coded magic numbers inside CallQualityCalculator and calls.js.
 * Tuning them required a code change. They're operational knobs — the
 * customer base's network quality varies (Lagos LTE vs Abuja fibre vs
 * rural 3G) and calibration shifts as more data comes in.
 *
 * Override any of these in .env without redeploying:
 *
 *   VOICE_MOS_R_FACTOR_MAX=93.2
 *   VOICE_MOS_PACKET_LOSS_WEIGHT=4.0
 *   VOICE_MOS_JITTER_WEIGHT_PER_MS=0.08
 *   VOICE_MOS_RTT_WEIGHT_PER_MS=0.032
 *   VOICE_STUN_URLS="stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302"
 */
return [

    /*
    |--------------------------------------------------------------------------
    | MOS (Mean Opinion Score) calibration
    |--------------------------------------------------------------------------
    | ITU-T G.107 E-model approximation. Defaults are the de-facto industry
    | values used by Twilio, Vonage, etc. The R-factor starts at the
    | theoretical maximum (93.2) and subtracts a weighted contribution from
    | each impairment metric, then maps the result through the standard
    | cubic polynomial to land between 1.0 and 4.5 (clamped to 5.0 for
    | floating-point edge cases).
    |
    | Tuning guidance: increasing a weight makes the score MORE sensitive
    | to that impairment. e.g. doubling JITTER_WEIGHT_PER_MS halves the
    | jitter at which the score drops a point.
    */
    'mos' => [
        'r_factor_max' => (float) env('VOICE_MOS_R_FACTOR_MAX', 93.2),
        'packet_loss_weight' => (float) env('VOICE_MOS_PACKET_LOSS_WEIGHT', 4.0),
        'jitter_weight_per_ms' => (float) env('VOICE_MOS_JITTER_WEIGHT_PER_MS', 0.08),
        'rtt_weight_per_ms' => (float) env('VOICE_MOS_RTT_WEIGHT_PER_MS', 0.032),
    ],

    /*
    |--------------------------------------------------------------------------
    | STUN / TURN servers for WebRTC ICE
    |--------------------------------------------------------------------------
    | Used by the browser RTCPeerConnection in calls.js (Meta inbound) and
    | by the Africa's Talking SDK (which accepts iceServers via constructor
    | options on most SDK versions).
    |
    | STUN alone works for most networks where both parties have public
    | IPv4 reflexive candidates. Restrictive NATs (corporate, some mobile
    | carriers) require a TURN relay — Phase 19b will introduce one. Until
    | then, leaving stun-only is correct.
    |
    | Comma-separated. Each value becomes one {urls: '...'} entry in the
    | ICE servers array. Empty string disables ICE entirely (rare —
    | only useful for local-network testing).
    */
    'stun_urls' => array_values(array_filter(
        explode(',', (string) env('VOICE_STUN_URLS', 'stun:stun.l.google.com:19302'))
    )),

    /*
    |--------------------------------------------------------------------------
    | TURN relay for WebRTC (restrictive NAT traversal)
    |--------------------------------------------------------------------------
    | STUN can't punch through symmetric NATs (corporate WiFi, some mobile
    | carriers) — those calls connect but have no audio ("dead air"). A TURN
    | relay fixes that by relaying the media. Set these to your TURN provider
    | (self-hosted coturn, or Twilio/Metered/etc.). Leave URLs empty to stay
    | STUN-only (the previous behaviour).
    |
    | Credentials are exposed to the browser (TURN auth is client-side by
    | design). Prefer short-lived/ephemeral credentials in production.
    |   VOICE_TURN_URLS="turn:turn.example.com:3478,turns:turn.example.com:5349"
    */
    'turn_urls' => array_values(array_filter(
        explode(',', (string) env('VOICE_TURN_URLS', ''))
    )),
    'turn_username' => env('VOICE_TURN_USERNAME'),
    'turn_credential' => env('VOICE_TURN_CREDENTIAL'),

    /*
    |--------------------------------------------------------------------------
    | Meta (WhatsApp Cloud) calling — OFF until GA
    |--------------------------------------------------------------------------
    | Meta's Cloud Calling API is not generally available, and the initiate /
    | accept / terminate request shapes in WhatsAppCloudApiService are
    | doc-guessed and unverified. Contact-initiated calls (ContactController::
    | startCall, ConversationController::initiateCall) route through
    | OutboundCallService → Meta and cannot currently connect the agent's audio.
    |
    | Keep this OFF (the default). The working outbound path is Africa's Talking
    | (calls.outbound / CallController::placeOutbound, driven by the in-chat call
    | button), which is unaffected by this flag. Flip to true only once Meta
    | Calling is GA and the request shapes have been verified against a live
    | account.
    */
    'meta_calling_enabled' => (bool) env('VOICE_META_CALLING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Call recording + AI analysis — OFF by default (compliance)
    |--------------------------------------------------------------------------
    | When enabled, the browser softphone records the mixed call audio and
    | uploads it to the private disk; a queued job sends it to Gemini for a
    | transcript + summary + key points shown on the Call Workspace panel.
    |
    | Recording people's calls has legal/consent implications (a "this call may
    | be recorded" notice + a retention policy are your responsibility), so the
    | default is OFF. The recording upload endpoint and the client recorder both
    | respect this flag — flip it to true in .env only once consent is handled.
    | GEMINI_API_KEY must also be set for the analysis half to run.
    */
    'call_recording_enabled' => (bool) env('VOICE_CALL_RECORDING_ENABLED', false),

    // Hard cap on an uploaded recording (kilobytes). Guards the upload endpoint
    // and keeps audio within Gemini's inline-request budget. ~25 MB default.
    'recording_max_kb' => (int) env('VOICE_RECORDING_MAX_KB', 25600),

    // Retention: the calls:prune-recordings command deletes the raw audio file
    // (keeping the transcript/summary) once it's older than this many days.
    // 0 = keep recordings forever (retention disabled). Set a real number to
    // honour a data-retention policy and cap storage growth.
    'recording_retention_days' => (int) env('VOICE_RECORDING_RETENTION_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Webhook hardening
    |--------------------------------------------------------------------------
    | The provider webhook endpoints are unauthenticated by nature. Two extra
    | defenses:
    |
    | - Rate limit (per IP/min) — abuse protection. Kept high so legitimate
    |   status-webhook bursts during a big campaign aren't dropped.
    | - IP allowlist — lock the endpoints to your provider's published source
    |   ranges (Meta / Africa's Talking). Supports individual IPs and CIDR.
    |   Empty = disabled (accept from anywhere, the previous behaviour).
    |     VOICE_WEBHOOK_IP_ALLOWLIST="1.2.3.0/24,5.6.7.8"
    */
    'webhook_rate_limit' => (int) env('VOICE_WEBHOOK_RATE_LIMIT', 600),
    'webhook_ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VOICE_WEBHOOK_IP_ALLOWLIST', '')),
    ))),

    /*
    | Africa's Talking voice-webhook shared secret. AT voice callbacks are
    | unsigned form-encoded POSTs, so the speculative HMAC scheme couldn't work.
    | Instead the callback URL carries an unguessable secret path segment
    | (/webhooks/africastalking/voice/{secret}) that we compare in constant time.
    | Set this + point AT's callback at the /{secret} URL. Empty = accept the
    | bare path (rely on the IP allowlist + rate limit only).
    */
    'at_webhook_secret' => env('AT_VOICE_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Inbound call-flow engine (all OFF by default — verify on live AT first)
    |--------------------------------------------------------------------------
    | These shape the Voice XML we return when a customer calls the virtual
    | number. Each is dark behind its own flag; with all off, inbound behaves
    | exactly as before (ring the assigned agent, else "agents busy").
    |
    | Order the engine applies them: business hours → IVR menu → destination
    | (agent / queue / voicemail).
    */
    'business_hours_enabled' => (bool) env('VOICE_BUSINESS_HOURS_ENABLED', false),
    'ivr_enabled' => (bool) env('VOICE_IVR_ENABLED', false),
    'queue_enabled' => (bool) env('VOICE_QUEUE_ENABLED', false),
    'voicemail_enabled' => (bool) env('VOICE_VOICEMAIL_ENABLED', false),
    'transfer_enabled' => (bool) env('VOICE_TRANSFER_ENABLED', false),

    // Business hours (used when business_hours_enabled). Times are HH:MM in this
    // timezone; a day absent/empty means closed all day. Overridable via a
    // Setting later; config is the simple start.
    'business_hours' => [
        'timezone' => env('VOICE_BUSINESS_TZ', 'Africa/Lagos'),
        'week' => [
            'mon' => ['09:00', '17:00'],
            'tue' => ['09:00', '17:00'],
            'wed' => ['09:00', '17:00'],
            'thu' => ['09:00', '17:00'],
            'fri' => ['09:00', '17:00'],
            'sat' => null,
            'sun' => null,
        ],
        'closed_message' => env('VOICE_CLOSED_MESSAGE',
            'Thank you for calling. Our office is currently closed. Please leave a message after the tone, or call back during business hours.'),
    ],

    // IVR menu (used when ivr_enabled). Each option maps a pressed digit to a
    // destination: agent (round-robin), queue (name), or voicemail.
    'ivr' => [
        'prompt' => env('VOICE_IVR_PROMPT',
            'Welcome. For sales, press 1. For support, press 2. To leave a voicemail, press 3.'),
        'timeout' => (int) env('VOICE_IVR_TIMEOUT', 15),
        'options' => [
            '1' => ['type' => 'agent',     'label' => 'Sales'],
            '2' => ['type' => 'queue',     'label' => 'Support', 'queue' => 'support'],
            '3' => ['type' => 'voicemail', 'label' => 'Voicemail'],
        ],
        'invalid_message' => 'Sorry, that is not a valid option.',
    ],

    // Queue (used when queue_enabled). holdMusic is a public URL AT can fetch.
    'queue' => [
        'default_name' => env('VOICE_QUEUE_NAME', 'support'),
        'hold_music_url' => env('VOICE_QUEUE_HOLD_MUSIC', ''),
        'timeout_seconds' => (int) env('VOICE_QUEUE_TIMEOUT', 300),
    ],

    // Voicemail (used when voicemail_enabled).
    'voicemail' => [
        'greeting' => env('VOICE_VOICEMAIL_GREETING',
            'Please leave your message after the tone. Press the hash key when you are done.'),
        'max_length_seconds' => (int) env('VOICE_VOICEMAIL_MAX_LENGTH', 120),
        // SSRF guard for the recording proxy: only these hosts (https only) are
        // fetched. Set to whatever host AT actually serves recordings from once
        // verified live (may be an S3 bucket rather than voice.africastalking.com).
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('VOICE_RECORDING_ALLOWED_HOSTS', 'voice.africastalking.com'),
        )))),
    ],

];
