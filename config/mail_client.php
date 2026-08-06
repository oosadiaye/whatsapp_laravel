<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-employee email client (Track B)
    |--------------------------------------------------------------------------
    |
    | Two-way mailbox: each employee connects THEIR OWN account and reads/replies
    | as themselves — distinct from the one-directional bulk-marketing email
    | (config/mail.php + EmailCampaign). Stays OFF until the whole feature is
    | live-verified end-to-end (plan step B6). Nothing references these settings
    | while `enabled` is false.
    |
    */

    // Defaults OFF to match .env.example and the "stays OFF until live-verified"
    // note above — a deploy that forgets this key must NOT silently ship the
    // per-employee mailbox (and its credential-storage/SSRF surface) enabled.
    'enabled' => (bool) env('MAIL_CLIENT_ENABLED', false),

    // Which provider adapter to offer for connecting accounts: gmail | graph | imap.
    'provider' => env('MAIL_CLIENT_PROVIDER'),

    // How often the scheduler fans out per-account inbound sync (B3).
    'sync_interval_minutes' => (int) env('MAIL_CLIENT_SYNC_INTERVAL', 2),

    // Per-account outbound send rate (B5).
    'send_rate_per_minute' => (int) env('MAIL_CLIENT_SEND_RATE', 30),

    // Hard cap on a single inbound message (body + attachments), KB. Messages
    // larger than this are skipped (never stored, attachments never written), so
    // a malicious sender can't exhaust a queue worker's memory or the private
    // disk. 0 = no cap.
    'max_inbound_kb' => (int) env('MAIL_CLIENT_MAX_INBOUND_KB', 25600),

    // Retention for stored message bodies (real mailbox PII). 0 = keep forever;
    // set a real number so `mailbox:prune-messages` can purge (B1/L4).
    'retention_days' => (int) env('MAIL_CLIENT_RETENTION_DAYS', 0),

];
