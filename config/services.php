<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Inbound provider bounce/complaint webhooks. The secret is an unguessable
    | token embedded in the callback URL path
    | (/webhooks/email/{provider}/{secret}); the endpoint fails closed (404)
    | until it is set. A hard bounce or spam complaint adds the address to the
    | EmailSuppression list so the send pipeline stops emailing it.
    */
    'email_webhooks' => [
        'secret' => env('EMAIL_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Google Gemini — transcribes + summarises call recordings for the Call
    | Workspace panel. Gemini is multimodal, so one request turns the audio
    | into a transcript AND structured key points. Leave the key blank to keep
    | the whole AI pipeline dormant (recordings just won't be analysed).
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // Flash is fast + cheap and accepts audio input; override per env if needed.
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    | ffmpeg — used only to remux browser recordings (Chrome emits webm/opus,
    | which Gemini doesn't accept) into ogg before analysis. Entirely optional:
    | if the binary isn't found the transcode is skipped and the original audio
    | is sent as-is. Default resolves "ffmpeg" from PATH.
    */
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],

];
