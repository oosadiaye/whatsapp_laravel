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

];
