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

    'enabled' => (bool) env('MAIL_CLIENT_ENABLED', false),

    // Which provider adapter to offer for connecting accounts: gmail | graph | imap.
    'provider' => env('MAIL_CLIENT_PROVIDER'),

    // How often the scheduler fans out per-account inbound sync (B3).
    'sync_interval_minutes' => (int) env('MAIL_CLIENT_SYNC_INTERVAL', 2),

    // Per-account outbound send rate (B5).
    'send_rate_per_minute' => (int) env('MAIL_CLIENT_SEND_RATE', 30),

    // Retention for stored message bodies (real mailbox PII). 0 = keep forever;
    // set a real number so `mailbox:prune-messages` can purge (B1/L4).
    'retention_days' => (int) env('MAIL_CLIENT_RETENTION_DAYS', 0),

];
